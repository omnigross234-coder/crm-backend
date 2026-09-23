<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3 certification defect B1: GET /api/admin/security-center/auth-events
 * ordered only by the requested column. auth_events.created_at has one-second
 * resolution, so a burst of events (the realistic shape of a credential-stuffing
 * attack) shares one timestamp, and offset pagination over a non-unique ORDER BY
 * is not stable: rows repeat on one page and never appear on another.
 *
 * These tests build exactly that dataset and assert what the defect broke:
 * every event appears exactly once across all pages, in a fully specified
 * order, and the query contract itself carries the unique tie-breaker.
 */
class SecurityCenterAuthEventsPaginationTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-01';

    private const TO = '2026-09-19';

    private function superAdmin(): User
    {
        return User::create([
            'client_id' => null,
            'name' => 'Super Admin',
            'email' => 'sc-page-'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, int>  $groups  created_at => number of events sharing that exact timestamp
     * @return array<int, array{id:int,created_at:string,event:string,result:string}> keyed by id
     */
    private function seedTiedEvents(array $groups, string $event = 'login_failed', string $result = 'failure'): array
    {
        $rows = [];
        foreach ($groups as $createdAt => $count) {
            for ($i = 0; $i < $count; $i++) {
                $rows[] = [
                    'event' => $event,
                    'result' => $result,
                    'login_identifier' => 'tie-'.count($rows).'@example.com',
                    'ip_address' => '198.51.100.7',
                    'created_at' => $createdAt,
                ];
            }
        }
        DB::table('auth_events')->insert($rows);

        $out = [];
        foreach (DB::table('auth_events')->orderBy('id')->get(['id', 'created_at', 'event', 'result']) as $r) {
            $out[(int) $r->id] = ['id' => (int) $r->id, 'created_at' => $r->created_at, 'event' => $r->event, 'result' => $r->result];
        }

        return $out;
    }

    /**
     * Requests every page independently, exactly as the UI does, and returns
     * the concatenated ids plus the meta of the first page.
     *
     * @return array{ids: int[], meta: array<string,mixed>, pages: int}
     */
    private function collectAllPages(User $admin, string $query, int $perPage): array
    {
        $ids = [];
        $meta = null;
        $page = 1;
        do {
            $response = $this->actingAs($admin)->getJson(
                '/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO
                ."&per_page={$perPage}&page={$page}&{$query}"
            );
            $response->assertOk();
            $meta ??= $response->json('meta');
            foreach ($response->json('data') as $row) {
                $ids[] = $row['id'];
            }
            $last = $response->json('meta.last_page');
            $page++;
        } while ($page <= $last);

        return ['ids' => $ids, 'meta' => $meta, 'pages' => $last];
    }

    /**
     * @param  array<int, array<string,mixed>>  $rows
     * @return int[]
     */
    private function expectedOrder(array $rows, string $column, string $dir): array
    {
        $rows = array_values($rows);
        usort($rows, function ($a, $b) use ($column, $dir) {
            $primary = strcmp((string) $a[$column], (string) $b[$column]);
            $result = $primary !== 0 ? $primary : ($a['id'] <=> $b['id']);

            return $dir === 'desc' ? -$result : $result;
        });

        return array_column($rows, 'id');
    }

    // ── B1: completeness and determinism over tied timestamps ─────────

    public static function tiedPaginationCases(): array
    {
        return [
            'desc, 25 per page' => ['desc', 25],
            'asc, 25 per page' => ['asc', 25],
            'desc, 7 per page' => ['desc', 7],
            'asc, 100 per page' => ['asc', 100],
        ];
    }

    #[DataProvider('tiedPaginationCases')]
    public function test_pagination_over_identical_timestamps_shows_every_event_exactly_once(string $dir, int $perPage): void
    {
        // 60 + 45 + 25 events, each group sharing ONE exact created_at.
        $rows = $this->seedTiedEvents([
            '2026-09-10 10:00:00' => 60,
            '2026-09-11 11:30:00' => 45,
            '2026-09-12 09:15:00' => 25,
        ]);
        $this->assertCount(130, $rows);

        $result = $this->collectAllPages($this->superAdmin(), "sort_by=created_at&sort_dir={$dir}", $perPage);

        $this->assertSame(130, $result['meta']['total'], 'meta.total must stay correct');
        $this->assertSame((int) ceil(130 / $perPage), $result['pages']);

        $this->assertCount(130, $result['ids'], 'pages together must return exactly total rows');
        $this->assertSame(
            count($result['ids']),
            count(array_unique($result['ids'])),
            'no event id may appear on more than one page'
        );
        $missing = array_diff(array_keys($rows), $result['ids']);
        $this->assertSame([], array_values($missing), 'no event id may be missing from the paged result');

        $this->assertSame(
            $this->expectedOrder($rows, 'created_at', $dir),
            $result['ids'],
            "order must be created_at {$dir}, then id {$dir}"
        );
    }

    public function test_default_request_is_deterministic_and_repeatable(): void
    {
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 80]);
        $admin = $this->superAdmin();

        $first = $this->collectAllPages($admin, '', 25);
        $second = $this->collectAllPages($admin, '', 25);

        $this->assertSame($first['ids'], $second['ids'], 'the same request must always return the same order');
        $this->assertCount(80, array_unique($first['ids']));
    }

    public static function otherSortColumns(): array
    {
        return [
            'event asc' => ['event', 'asc'],
            'event desc' => ['event', 'desc'],
            'result asc' => ['result', 'asc'],
            'result desc' => ['result', 'desc'],
        ];
    }

    /**
     * sort_by=event / result ties are far heavier than timestamp ties (only 6
     * / 2 distinct values), so the same tie-breaker must cover them.
     */
    #[DataProvider('otherSortColumns')]
    public function test_sorting_by_a_low_cardinality_column_is_also_complete_and_ordered(string $column, string $dir): void
    {
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 40], 'login_failed', 'failure');
        $rows = $this->seedTiedEvents(['2026-09-10 10:00:00' => 40], 'login_success', 'success');
        $this->assertCount(80, $rows, 'seedTiedEvents returns every event currently in the table');

        $result = $this->collectAllPages($this->superAdmin(), "sort_by={$column}&sort_dir={$dir}", 25);

        $this->assertCount(80, $result['ids']);
        $this->assertCount(80, array_unique($result['ids']));
        $this->assertSame($this->expectedOrder($rows, $column, $dir), $result['ids']);
    }

    public function test_filtered_pagination_over_ties_is_complete(): void
    {
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 60], 'login_failed', 'failure');
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 30], 'logout', 'success');

        $result = $this->collectAllPages($this->superAdmin(), 'event=login_failed&sort_by=created_at&sort_dir=desc', 25);

        $this->assertSame(60, $result['meta']['total']);
        $this->assertCount(60, $result['ids']);
        $this->assertCount(60, array_unique($result['ids']));
    }

    /**
     * Guards against fixing B1 by REPLACING the requested ordering with id:
     * the timestamp must stay the primary key, id only breaks ties.
     */
    public function test_timestamp_stays_the_primary_sort_key_and_id_only_breaks_ties(): void
    {
        // ids are ascending but timestamps run BACKWARDS, so "order by id"
        // and "order by created_at" give opposite answers.
        DB::table('auth_events')->insert([
            ['event' => 'login_failed', 'result' => 'failure', 'created_at' => '2026-09-15 10:00:00'],
            ['event' => 'login_failed', 'result' => 'failure', 'created_at' => '2026-09-14 10:00:00'],
            ['event' => 'login_failed', 'result' => 'failure', 'created_at' => '2026-09-13 10:00:00'],
            ['event' => 'login_failed', 'result' => 'failure', 'created_at' => '2026-09-13 10:00:00'],
        ]);
        $ids = DB::table('auth_events')->orderBy('id')->pluck('id')->all();
        $admin = $this->superAdmin();

        $desc = $this->actingAs($admin)->getJson('/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO.'&sort_dir=desc')->json('data.*.id');
        $asc = $this->actingAs($admin)->getJson('/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO.'&sort_dir=asc')->json('data.*.id');

        $this->assertSame([$ids[0], $ids[1], $ids[3], $ids[2]], $desc, 'desc = newest first; the 09-13 tie is broken by id desc');
        $this->assertSame([$ids[2], $ids[3], $ids[1], $ids[0]], $asc, 'asc = oldest first; the 09-13 tie is broken by id asc');
    }

    // ── B1: the query contract and its cost ───────────────────────────

    public static function directions(): array
    {
        return ['desc' => ['desc'], 'asc' => ['asc']];
    }

    #[DataProvider('directions')]
    public function test_the_query_orders_by_the_requested_column_then_id_in_the_same_direction(string $dir): void
    {
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 5]);
        $admin = $this->superAdmin();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($admin)
            ->getJson('/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO."&sort_by=created_at&sort_dir={$dir}")
            ->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $select = collect($queries)
            ->map(fn ($q) => str_replace(['`', '"'], '', $q))
            ->first(fn ($q) => str_contains($q, 'from auth_events') && str_contains($q, 'limit'));

        $this->assertNotNull($select, 'the paginated auth_events select must be present');
        $this->assertStringContainsString("order by created_at {$dir}, id {$dir}", $select);
    }

    public function test_the_tie_breaker_adds_no_queries_and_cost_does_not_scale_with_rows_or_page(): void
    {
        $client = Client::factory()->create();
        $user = User::create(['client_id' => $client->id, 'name' => 'U', 'email' => 'u-'.uniqid().'@example.com', 'password' => 'password', 'role' => 'admin', 'status' => 'active']);
        $rows = [];
        for ($i = 0; $i < 150; $i++) {
            $rows[] = ['event' => 'login_success', 'result' => 'success', 'user_id' => $user->id, 'client_id' => $client->id, 'created_at' => '2026-09-10 10:00:00'];
        }
        DB::table('auth_events')->insert($rows);
        $admin = $this->superAdmin();

        $count = function (string $query) use ($admin): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($admin)->getJson('/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO.'&'.$query)->assertOk();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $page1 = $count('per_page=25&page=1');
        $page6 = $count('per_page=25&page=6');
        $per100 = $count('per_page=100&page=1');
        $per5 = $count('per_page=5&page=1');

        $this->assertSame($page1, $page6, 'query count must not depend on the page');
        $this->assertSame($page1, $per100, 'query count must not depend on the number of rows returned (no N+1)');
        $this->assertSame($page1, $per5);
    }

    public function test_response_shape_is_unchanged(): void
    {
        $this->seedTiedEvents(['2026-09-10 10:00:00' => 3]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?from='.self::FROM.'&to='.self::TO);

        $response->assertOk()->assertJsonStructure([
            'success',
            'data' => [['id', 'event', 'result', 'failure_reason', 'login_identifier', 'ip_address', 'user', 'client', 'created_at']],
            'meta' => ['current_page', 'per_page', 'total', 'last_page'],
        ]);
        $this->assertSame(['success', 'data', 'meta'], array_keys($response->json()));
        $this->assertSame(['current_page', 'per_page', 'total', 'last_page'], array_keys($response->json('meta')));
    }
}
