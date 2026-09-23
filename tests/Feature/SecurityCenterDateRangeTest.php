<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Phase 3 certification defect B2: the 90-day maximum was checked only when
 * BOTH `from` and `to` were supplied, so `?from=1900-01-01` was accepted and
 * produced a 46,282-day overview. The rule under test is:
 *
 *   the EFFECTIVE range that reaches the query must never exceed 90 days.
 *
 * "Effective" means after defaults are applied (to = end of today, from =
 * to - 30 days) in the application's own timezone (config('app.timezone'),
 * Asia/Kolkata), using the same day-granular "difference" the original
 * both-dates rule used (exactly 90 days apart is allowed, 91 is not).
 *
 * Everything runs against a frozen clock: 2026-09-19 10:00 IST.
 */
class SecurityCenterDateRangeTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-09-19';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.timezone' => 'Asia/Kolkata']);
        $this->travelTo(Carbon::parse('2026-09-19 10:00:00', 'Asia/Kolkata'));
    }

    private function superAdmin(): User
    {
        return User::create([
            'client_id' => null,
            'name' => 'Super Admin',
            'email' => 'sc-range-'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function fetch(string $endpoint, string $query = '')
    {
        return $this->actingAs($this->superAdmin())
            ->getJson("/api/admin/security-center/{$endpoint}".($query === '' ? '' : "?{$query}"));
    }

    public static function endpoints(): array
    {
        return ['overview' => ['overview'], 'auth-events' => ['auth-events']];
    }

    // ── ACCEPTED: the effective range is within the allowance ─────────

    /**
     * query => [expected period.from, expected period.to]
     */
    public static function acceptedRanges(): array
    {
        return [
            'A/L neither from nor to = default trailing 30 days' => ['', '2026-08-20', '2026-09-19'],
            'B valid from + to' => ['from=2026-09-01&to=2026-09-17', '2026-09-01', '2026-09-17'],
            'B both, exactly 90 days apart (boundary)' => ['from=2026-06-19&to=2026-09-17', '2026-06-19', '2026-09-17'],
            'C from only, well within limit' => ['from=2026-07-01', '2026-07-01', '2026-09-19'],
            'C from only, exactly 90 days before today (boundary)' => ['from=2026-06-21', '2026-06-21', '2026-09-19'],
            'C from only, today (single day)' => ['from=2026-09-19', '2026-09-19', '2026-09-19'],
            'E to only, within limit (from defaults to to-30d)' => ['to=2026-09-01', '2026-08-02', '2026-09-01'],
            'F to only, ancient: effective width is still 30 days' => ['to=1900-01-01', '1899-12-02', '1900-01-01'],
            'F to only, far future: effective width is still 30 days' => ['to=2100-01-01', '2099-12-02', '2100-01-01'],
            'F to only, 9999-12-31 does not overflow or throw' => ['to=9999-12-31', '9999-12-01', '9999-12-31'],
        ];
    }

    #[DataProvider('acceptedRanges')]
    public function test_overview_accepts_and_resolves_the_effective_range(string $query, string $from, string $to): void
    {
        $response = $this->fetch('overview', $query);

        $response->assertOk()
            ->assertJsonPath('data.period.from', $from)
            ->assertJsonPath('data.period.to', $to);

        $days = Carbon::parse($response->json('data.period.from'))
            ->diffInDays(Carbon::parse($response->json('data.period.to')), absolute: true);
        $this->assertLessThanOrEqual(90, $days, 'the effective range sent to the query must never exceed 90 days');
    }

    #[DataProvider('acceptedRanges')]
    public function test_auth_events_accepts_the_same_ranges(string $query, string $from, string $to): void
    {
        $this->fetch('auth-events', $query)->assertOk();
    }

    // ── REJECTED ──────────────────────────────────────────────────────

    /**
     * query => the validation error key that must be present
     */
    public static function rejectedRanges(): array
    {
        return [
            'D from only, 91 days before today (just over)' => ['from=2026-06-20', 'from'],
            'D from only, 1900-01-01' => ['from=1900-01-01', 'from'],
            'D from only, 2000-01-01' => ['from=2000-01-01', 'from'],
            'D from only, unix epoch via @0' => ['from=@0', 'from'],
            'D from only, relative string that resolves far in the past' => ['from=-40 years', 'from'],
            'I from only, in the future = reversed effective range' => ['from=2026-09-20', 'from'],
            'I from only, 9999-12-31 = reversed effective range' => ['from=9999-12-31', 'from'],
            'H both, 91 days apart (just over)' => ['from=2026-06-20&to=2026-09-19', 'to'],
            'H both, way over' => ['from=2020-01-01&to=2026-12-31', 'to'],
            'I both, reversed' => ['from=2026-09-17&to=2026-09-01', 'to'],
            'J malformed from' => ['from=not-a-date', 'from'],
            'J impossible from month/day' => ['from=2026-13-45', 'from'],
            'J from as an array' => ['from[]=2026-09-01', 'from'],
            'K malformed to' => ['to=not-a-date', 'to'],
            'K malformed to next to a valid from' => ['from=2026-09-01&to=2026-99-99', 'to'],
            'K to as an array' => ['to[]=2026-09-01', 'to'],
        ];
    }

    #[DataProvider('rejectedRanges')]
    public function test_overview_rejects(string $query, string $errorKey): void
    {
        $response = $this->fetch('overview', $query);

        $response->assertStatus(422);
        $this->assertArrayHasKey($errorKey, $response->json('data'), 'validation errors: '.json_encode($response->json('data')));
        $this->assertFalse($response->json('success'));
    }

    #[DataProvider('rejectedRanges')]
    public function test_auth_events_rejects(string $query, string $errorKey): void
    {
        $response = $this->fetch('auth-events', $query);

        $response->assertStatus(422);
        $this->assertArrayHasKey($errorKey, $response->json('data'), 'validation errors: '.json_encode($response->json('data')));
    }

    #[DataProvider('endpoints')]
    public function test_the_rejection_message_is_the_established_one(string $endpoint): void
    {
        $response = $this->fetch($endpoint, 'from=1900-01-01');

        $this->assertSame(['The date range must not exceed 90 days.'], $response->json('data.from'));
    }

    // ── M: the application's timezone (Asia/Kolkata), not UTC ─────────

    #[DataProvider('endpoints')]
    public function test_the_90_day_boundary_moves_at_kolkata_midnight_not_utc_midnight(string $endpoint): void
    {
        // 23:59:59 IST on 2026-09-18 is 18:29:59 UTC (still the 18th in UTC too).
        // "Today" is the 18th, so from=2026-06-20 is exactly 90 days back: allowed.
        $this->travelTo(Carbon::parse('2026-09-18 18:29:59', 'UTC'));
        $this->fetch($endpoint, 'from=2026-06-20')->assertOk();

        // 18:30:01 UTC is 00:00:01 IST on the 19th — but STILL the 18th in UTC.
        // An implementation using UTC would keep saying "today = 18th" and
        // wrongly accept it; in the app's timezone it is 91 days back: rejected.
        $this->travelTo(Carbon::parse('2026-09-18 18:30:01', 'UTC'));
        $this->fetch($endpoint, 'from=2026-06-20')->assertStatus(422);
    }

    public function test_default_period_follows_the_kolkata_day_across_midnight(): void
    {
        $this->travelTo(Carbon::parse('2026-09-18 18:29:59', 'UTC'));
        $before = $this->fetch('overview');
        $this->travelTo(Carbon::parse('2026-09-18 18:30:01', 'UTC'));
        $after = $this->fetch('overview');

        $this->assertSame('2026-09-18', $before->json('data.period.to'));
        $this->assertSame('2026-08-19', $before->json('data.period.from'));
        $this->assertSame('2026-09-19', $after->json('data.period.to'));
        $this->assertSame('2026-08-20', $after->json('data.period.from'));
    }

    // ── The effective range is what actually reaches the query ────────

    public function test_from_only_query_returns_exactly_the_rows_inside_the_resolved_range(): void
    {
        DB::table('auth_events')->insert([
            ['event' => 'login_failed', 'result' => 'failure', 'login_identifier' => 'before-1', 'created_at' => '2026-06-20 23:59:59'],
            ['event' => 'login_failed', 'result' => 'failure', 'login_identifier' => 'start-2', 'created_at' => '2026-06-21 00:00:00'],
            ['event' => 'login_failed', 'result' => 'failure', 'login_identifier' => 'today-3', 'created_at' => '2026-09-19 09:00:00'],
            ['event' => 'login_failed', 'result' => 'failure', 'login_identifier' => 'today-4', 'created_at' => '2026-09-19 23:59:59'],
            ['event' => 'login_failed', 'result' => 'failure', 'login_identifier' => 'after-5', 'created_at' => '2026-09-20 00:00:00'],
        ]);

        $response = $this->fetch('auth-events', 'from=2026-06-21&per_page=100');

        $response->assertOk();
        $this->assertEqualsCanonicalizing(
            ['start-2', 'today-3', 'today-4'],
            $response->json('data.*.login_identifier')
        );
        $this->assertSame(3, $response->json('meta.total'));

        $counts = $this->fetch('overview', 'from=2026-06-21');
        $this->assertSame(3, $counts->json('data.authentication.login_failed_count'));
    }

    public function test_an_oversized_range_is_rejected_before_any_aggregation_query_runs(): void
    {
        $admin = $this->superAdmin();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($admin)->getJson('/api/admin/security-center/overview?from=1900-01-01')->assertStatus(422);

        $touched = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn ($q) => str_contains($q, 'auth_events') || str_contains($q, 'activity_logs') || str_contains($q, 'personal_access_tokens'));
        DB::disableQueryLog();

        $this->assertCount(0, $touched, 'validation must fail before overview aggregates touch auth_events / activity_logs / tokens');
    }

    public function test_response_shape_of_a_successful_overview_is_unchanged(): void
    {
        $response = $this->fetch('overview', 'from=2026-07-01');

        $response->assertOk()->assertJsonStructure([
            'success',
            'data' => ['period' => ['from', 'to'], 'authentication', 'sessions', 'audit_activity', 'backups', 'rate_limiting', 'security_headers', 'health'],
        ]);
    }
}
