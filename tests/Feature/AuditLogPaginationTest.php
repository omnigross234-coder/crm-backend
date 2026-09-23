<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Independent certification finding A1: GET /api/audit-logs ordered only by the
 * requested column (created_at by default). created_at has one-second resolution,
 * so a burst of activity shares one timestamp and offset pagination over a
 * non-unique ORDER BY is not stable: rows repeat on one page and never appear on
 * another. (Same defect class as the Security Center's B1.)
 *
 * These tests build that dataset and assert what the defect broke — every row
 * exactly once across all pages, in a fully specified order — plus the SQL
 * contract, the cost, the existing filters and the unchanged access rules.
 */
class AuditLogPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const SORT_COLUMNS = ['created_at', 'action', 'module', 'client_id', 'user_id'];

    private function user(string $role, ?int $clientId = null): User
    {
        return User::create([
            'client_id' => $clientId,
            'name' => ucfirst($role).' User',
            'email' => uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, int>  $groups  created_at => number of rows sharing that exact timestamp
     * @param  array<string, mixed>  $attrs  extra column values for every row of the call
     * @return array<int, array<string,mixed>> every activity_logs row currently in the table, keyed by id
     */
    private function seedTied(array $groups, array $attrs = []): array
    {
        $rows = [];
        foreach ($groups as $createdAt => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = array_merge([
                    'client_id' => null, 'user_id' => null, 'action' => 'test.action', 'subject_type' => null, 'subject_id' => null,
                    'meta' => '[]', 'module' => 'Test', 'record_id' => null, 'description' => 'row-'.count($rows),
                    'ip_address' => '198.51.100.7', 'created_at' => $createdAt, 'updated_at' => $createdAt,
                ], $attrs);
            }
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('activity_logs')->insert($chunk);
        }

        return $this->snapshot();
    }

    /** @return array<int, array<string,mixed>> */
    private function snapshot(): array
    {
        $out = [];
        foreach (DB::table('activity_logs')->get(['id', 'created_at', 'action', 'module', 'client_id', 'user_id']) as $r) {
            $out[(int) $r->id] = (array) $r;
        }

        return $out;
    }

    /**
     * Requests every page independently, as the UI does; returns ids in the order received plus meta.
     *
     * @return array{ids:int[], meta:array<string,mixed>, pages:int}
     */
    private function collect(User $admin, string $query, int $perPage): array
    {
        $ids = [];
        $meta = null;
        $page = 1;
        do {
            $r = $this->actingAs($admin)->getJson("/api/audit-logs?per_page={$perPage}&page={$page}&{$query}");
            $r->assertOk();
            $meta ??= $r->json('meta');
            foreach ($r->json('data') as $row) {
                $ids[] = $row['id'];
            }
            $last = $r->json('meta.last_page');
            $page++;
        } while ($page <= $last);

        return ['ids' => $ids, 'meta' => $meta, 'pages' => $last];
    }

    /**
     * Expected complete order: primary column, THEN id, both in the requested direction (NULL sorts lowest, like MySQL/SQLite ASC).
     *
     * @param  array<int, array<string,mixed>>  $rows
     * @return int[]
     */
    private function expected(array $rows, string $column, string $dir): array
    {
        $rows = array_values($rows);
        usort($rows, function ($a, $b) use ($column, $dir) {
            $x = $a[$column];
            $y = $b[$column];
            $c = $x === $y ? 0 : ($x === null ? -1 : ($y === null ? 1 : (is_numeric($x) && is_numeric($y) ? $x <=> $y : strcmp((string) $x, (string) $y))));
            if ($c === 0) {
                $c = $a['id'] <=> $b['id'];
            }

            return $dir === 'desc' ? -$c : $c;
        });

        return array_column($rows, 'id');
    }

    // ── A1: completeness and determinism over tied timestamps ─────────

    public static function timestampCases(): array
    {
        $cases = [];
        foreach (['desc', 'asc'] as $dir) {
            foreach ([10, 25, 50] as $per) {
                $cases["created_at {$dir}, {$per} per page"] = [$dir, $per];
            }
        }

        return $cases;
    }

    #[DataProvider('timestampCases')]
    public function test_pagination_over_identical_timestamps_shows_every_row_exactly_once(string $dir, int $perPage): void
    {
        $rows = $this->seedTied([
            '2026-09-10 10:00:00' => 90,
            '2026-09-11 11:30:00' => 60,
            '2026-09-12 09:15:00' => 25,
            '2026-09-13 08:00:00' => 1,
        ]);
        $this->assertCount(176, $rows);

        $r = $this->collect($this->user('super_admin'), "sort_by=created_at&sort_dir={$dir}", $perPage);

        $this->assertSame(176, $r['meta']['total'], 'meta.total must stay correct');
        $this->assertSame((int) ceil(176 / $perPage), $r['pages']);
        $this->assertCount(176, $r['ids'], 'pages together must return exactly total rows');
        $this->assertSame(count($r['ids']), count(array_unique($r['ids'])), 'no id may appear on more than one page');
        $this->assertSame([], array_values(array_diff(array_keys($rows), $r['ids'])), 'no id may be missing');
        $this->assertSame($this->expected($rows, 'created_at', $dir), $r['ids'], "order must be created_at {$dir}, then id {$dir}");
    }

    public function test_default_request_is_deterministic_and_repeatable(): void
    {
        $this->seedTied(['2026-09-10 10:00:00' => 80]);
        $admin = $this->user('super_admin');

        $first = $this->collect($admin, '', 25);
        $second = $this->collect($admin, '', 25);

        $this->assertSame($first['ids'], $second['ids']);
        $this->assertCount(80, array_unique($first['ids']));
    }

    public static function otherColumns(): array
    {
        $cases = [];
        foreach (['action', 'module', 'client_id', 'user_id'] as $col) {
            foreach (['asc', 'desc'] as $dir) {
                $cases["{$col} {$dir}"] = [$col, $dir];
            }
        }

        return $cases;
    }

    /** Ties on action/module/client_id/user_id are far heavier than timestamp ties; the same tie-breaker must cover them. */
    #[DataProvider('otherColumns')]
    public function test_sorting_by_a_low_cardinality_column_is_complete_and_ordered(string $column, string $dir): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $actorA = $this->user('sales', $clientA->id);
        $actorB = $this->user('sales', $clientB->id);
        $this->seedTied(['2026-09-10 10:00:00' => 30], ['action' => 'a.one', 'module' => 'M1', 'client_id' => $clientA->id, 'user_id' => $actorA->id]);
        $this->seedTied(['2026-09-10 10:00:00' => 30], ['action' => 'b.two', 'module' => 'M2', 'client_id' => $clientB->id, 'user_id' => $actorB->id]);
        $rows = $this->seedTied(['2026-09-11 10:00:00' => 20], ['action' => 'a.one', 'module' => 'M1', 'client_id' => null, 'user_id' => null]);
        $this->assertCount(80, $rows);

        $r = $this->collect($this->user('super_admin'), "sort_by={$column}&sort_dir={$dir}", 25);

        $this->assertCount(80, $r['ids']);
        $this->assertCount(80, array_unique($r['ids']));
        $this->assertSame($this->expected($rows, $column, $dir), $r['ids']);
    }

    public function test_timestamp_stays_the_primary_sort_key_and_id_only_breaks_ties(): void
    {
        // ids ascend while timestamps run BACKWARDS: "order by id" and "order by created_at" disagree.
        DB::table('activity_logs')->insert([
            ['action' => 'x', 'module' => 'T', 'created_at' => '2026-09-15 10:00:00', 'updated_at' => '2026-09-15 10:00:00'],
            ['action' => 'x', 'module' => 'T', 'created_at' => '2026-09-14 10:00:00', 'updated_at' => '2026-09-14 10:00:00'],
            ['action' => 'x', 'module' => 'T', 'created_at' => '2026-09-13 10:00:00', 'updated_at' => '2026-09-13 10:00:00'],
            ['action' => 'x', 'module' => 'T', 'created_at' => '2026-09-13 10:00:00', 'updated_at' => '2026-09-13 10:00:00'],
        ]);
        $ids = DB::table('activity_logs')->orderBy('id')->pluck('id')->all();
        $admin = $this->user('super_admin');

        $desc = $this->actingAs($admin)->getJson('/api/audit-logs?sort_dir=desc')->json('data.*.id');
        $asc = $this->actingAs($admin)->getJson('/api/audit-logs?sort_dir=asc')->json('data.*.id');

        $this->assertSame([$ids[0], $ids[1], $ids[3], $ids[2]], $desc);
        $this->assertSame([$ids[2], $ids[3], $ids[1], $ids[0]], $asc);
    }

    // ── existing filters keep working, and stay complete over ties ────

    public function test_existing_filters_still_work_and_are_complete_over_ties(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $this->seedTied(['2026-09-10 10:00:00' => 60], ['action' => 'client.updated', 'module' => 'Client', 'client_id' => $clientA->id, 'subject_type' => Client::class, 'subject_id' => $clientA->id, 'description' => 'alpha update']);
        $this->seedTied(['2026-09-10 10:00:00' => 45], ['action' => 'lead.created', 'module' => 'Lead', 'client_id' => $clientB->id, 'description' => 'beta lead']);
        $this->seedTied(['2026-06-01 10:00:00' => 20], ['action' => 'client.updated', 'module' => 'Client', 'client_id' => $clientA->id, 'subject_type' => Client::class, 'subject_id' => $clientA->id, 'description' => 'old alpha']);
        $admin = $this->user('super_admin');

        $checks = [
            'action' => ['action=client.updated', 80],
            'client_id' => ["client_id={$clientB->id}", 45],
            'resource+subject_id' => ["resource=client&subject_id={$clientA->id}", 80],
            'search' => ['search=alpha', 80],
            'from/to' => ['from=2026-09-01&to=2026-09-30', 105],
            'combined' => ["action=client.updated&client_id={$clientA->id}&from=2026-09-01&to=2026-09-30", 60],
        ];
        foreach ($checks as $name => [$query, $count]) {
            $r = $this->collect($admin, $query, 10);
            $this->assertSame($count, $r['meta']['total'], "{$name}: total");
            $this->assertCount($count, $r['ids'], "{$name}: rows returned");
            $this->assertCount($count, array_unique($r['ids']), "{$name}: no duplicates over ties");
        }
    }

    // ── the SQL contract and its cost ─────────────────────────────────

    public static function sortCombinations(): array
    {
        $cases = [];
        foreach (self::SORT_COLUMNS as $col) {
            foreach (['asc', 'desc'] as $dir) {
                $cases["{$col} {$dir}"] = [$col, $dir];
            }
        }

        return $cases;
    }

    #[DataProvider('sortCombinations')]
    public function test_the_query_orders_by_the_requested_column_then_id_in_the_same_direction(string $column, string $dir): void
    {
        $this->seedTied(['2026-09-10 10:00:00' => 5]);
        $admin = $this->user('super_admin');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)->getJson("/api/audit-logs?sort_by={$column}&sort_dir={$dir}")->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $select = collect($queries)->map(fn ($q) => str_replace(['`', '"'], '', $q))
            ->first(fn ($q) => str_contains($q, 'from activity_logs') && str_contains($q, 'limit'));
        $this->assertNotNull($select);
        $this->assertStringContainsString("order by {$column} {$dir}, id {$dir}", $select);
    }

    public function test_the_default_ordering_is_created_at_desc_then_id_desc(): void
    {
        $this->seedTied(['2026-09-10 10:00:00' => 3]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->user('super_admin'))->getJson('/api/audit-logs')->assertOk();
        $select = collect(array_column(DB::getQueryLog(), 'query'))->map(fn ($q) => str_replace(['`', '"'], '', $q))
            ->first(fn ($q) => str_contains($q, 'from activity_logs') && str_contains($q, 'limit'));
        DB::disableQueryLog();

        $this->assertStringContainsString('order by created_at desc, id desc', $select);
    }

    public function test_the_tie_breaker_adds_no_queries_and_cost_does_not_scale_with_rows_or_page(): void
    {
        $client = Client::factory()->create();
        $actor = $this->user('sales', $client->id);
        $this->seedTied(['2026-09-10 10:00:00' => 150], ['client_id' => $client->id, 'user_id' => $actor->id]);
        $admin = $this->user('super_admin');

        $count = function (string $query) use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson("/api/audit-logs?{$query}")->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $p1 = $count('per_page=25&page=1');
        $this->assertSame($p1, $count('per_page=25&page=6'), 'query count must not depend on the page');
        $this->assertSame($p1, $count('per_page=100&page=1'), 'no N+1: cost must not scale with rows returned');
        $this->assertSame($p1, $count('per_page=5&page=1'));
    }

    public function test_response_shape_is_unchanged(): void
    {
        $client = Client::factory()->create();
        $this->seedTied(['2026-09-10 10:00:00' => 3], ['client_id' => $client->id, 'meta' => json_encode(['note' => 'x', 'token' => 'secret'])]);

        $r = $this->actingAs($this->user('super_admin'))->getJson('/api/audit-logs')->assertOk();

        $r->assertJsonStructure([
            'success',
            'data' => [['id', 'action', 'module', 'subject_type', 'subject_id', 'description', 'meta', 'ip_address', 'actor', 'client', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
        $this->assertSame(['success', 'data', 'meta'], array_keys($r->json()));
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($r->json('meta')));
        $this->assertSame('[redacted]', $r->json('data.0.meta.token'), 'redaction unchanged');
    }

    // ── authorization and tenant behaviour are unchanged ──────────────

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/audit-logs')->assertStatus(401);
        $this->getJson('/api/audit-logs?sort_by=created_at&sort_dir=asc&page=2')->assertStatus(401);
    }

    public static function deniedRoles(): array
    {
        return ['client_admin' => ['client_admin'], 'admin' => ['admin'], 'sales' => ['sales'], 'sales_employee' => ['sales_employee'], 'sales_manager' => ['sales_manager']];
    }

    #[DataProvider('deniedRoles')]
    public function test_every_tenant_role_remains_forbidden_even_with_tampered_parameters(string $role): void
    {
        $own = Client::factory()->create();
        $other = Client::factory()->create();
        $this->seedTied(['2026-09-10 10:00:00' => 5], ['client_id' => $other->id]);
        $user = $this->user($role, $own->id);

        foreach (['', '?client_id='.$own->id, '?client_id='.$other->id, '?sort_by=user_id&sort_dir=asc', '?per_page=100&page=1', '?sort_by=password', '?from=1900-01-01'] as $q) {
            $this->actingAs($user)->getJson('/api/audit-logs'.$q)->assertForbidden();
        }
    }

    public function test_super_admin_sees_platform_wide_rows_and_client_id_only_narrows(): void
    {
        $a = Client::factory()->create();
        $b = Client::factory()->create();
        $this->seedTied(['2026-09-10 10:00:00' => 12], ['client_id' => $a->id]);
        $this->seedTied(['2026-09-10 10:00:00' => 7], ['client_id' => $b->id]);
        $admin = $this->user('super_admin');

        $this->assertSame(19, $this->actingAs($admin)->getJson('/api/audit-logs?per_page=100')->json('meta.total'));
        $narrow = $this->actingAs($admin)->getJson("/api/audit-logs?client_id={$b->id}&per_page=100");
        $this->assertSame(7, $narrow->json('meta.total'));
        $this->assertSame([$b->id], array_values(array_unique(array_map(fn ($row) => $row['client']['id'], $narrow->json('data')))));
    }
}
