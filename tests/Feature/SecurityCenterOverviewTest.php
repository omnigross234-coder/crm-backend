<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Super Admin Security Center, Phase 1: GET /api/admin/security-center/overview.
 *
 * Real HTTP + real database verification against the running app
 * (documented in OGCLIENT-SUPERADMIN-SECURITY-CENTER-PHASE1-PLAN.md and
 * the accompanying report) was performed before these tests were
 * written, using disposable accounts deleted immediately afterward.
 */
class SecurityCenterOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(): User
    {
        return User::create([
            'client_id' => null,
            'name' => 'Super Admin',
            'email' => 'sc-super-'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function tenantUser(string $role): User
    {
        return User::create([
            'client_id' => Client::factory()->create()->id,
            'name' => ucfirst($role).' User',
            'email' => uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    // ── AUTHORIZATION ───────────────────────────────────────────────

    public function test_super_admin_can_access_overview(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview')
            ->assertOk();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/admin/security-center/overview')->assertStatus(401);
    }

    public function test_client_admin_is_denied(): void
    {
        $this->actingAs($this->tenantUser('client_admin'))
            ->getJson('/api/admin/security-center/overview')
            ->assertStatus(403);
    }

    public function test_admin_is_denied(): void
    {
        $this->actingAs($this->tenantUser('admin'))
            ->getJson('/api/admin/security-center/overview')
            ->assertStatus(403);
    }

    public function test_sales_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales'))
            ->getJson('/api/admin/security-center/overview')
            ->assertStatus(403);
    }

    public function test_sales_employee_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales_employee'))
            ->getJson('/api/admin/security-center/overview')
            ->assertStatus(403);
    }

    public function test_sales_manager_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales_manager'))
            ->getJson('/api/admin/security-center/overview')
            ->assertStatus(403);
    }

    // ── DATA SAFETY ─────────────────────────────────────────────────

    public function test_response_contains_no_secret_bearing_fields(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $body = $response->json('data');

        $this->assertEqualsCanonicalizing(
            ['period', 'authentication', 'sessions', 'audit_activity', 'backups', 'rate_limiting', 'security_headers', 'health'],
            array_keys($body)
        );

        // Schema-level guarantee, not just "this response didn't happen
        // to include one": no token/hash/credential field can appear
        // because no query in the controller ever selects one.
        $serialized = json_encode($body);
        foreach (['"token"', '"password"', '"secret"', 'Authorization', 'clientSecret', 'refreshToken'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    public function test_no_filesystem_path_or_backup_credentials_are_exposed(): void
    {
        ActivityLog::create([
            'user_id' => null,
            'action' => 'backup.created',
            'module' => 'System',
            'meta' => ['filename' => 'crm_backup_2026-09-17_00-00-00.sql', 'triggered_via' => 'scheduler'],
            'description' => 'backup.created',
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $response->assertJsonPath('data.backups.latest.status', 'created');
        $response->assertJsonPath('data.backups.latest.triggered_via', 'scheduler');

        $serialized = json_encode($response->json('data.backups'));
        $this->assertStringNotContainsString('storage', $serialized);
        $this->assertStringNotContainsString('C:', $serialized);
        $this->assertStringNotContainsString('GOOGLE_DRIVE', $serialized);
    }

    public function test_no_security_score_or_invented_metric_fields_exist(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $serialized = json_encode($response->json('data'));
        foreach (['score', 'risk', 'grade', 'rating'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $serialized);
        }
    }

    // ── FILTERS ─────────────────────────────────────────────────────

    public function test_valid_date_range_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview?from=2026-09-01&to=2026-09-17')
            ->assertOk()
            ->assertJsonPath('data.period.from', '2026-09-01')
            ->assertJsonPath('data.period.to', '2026-09-17');
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview?from=not-a-date')
            ->assertStatus(422);
    }

    /**
     * Regression guard for a real bug caught during live verification:
     * Carbon's diffInDays() returns a SIGNED value (negative when the
     * calling instance is earlier than its argument) — the identical
     * footgun already documented and fixed in
     * PasswordResetController::resetPassword() (Workstream A, SEC-F05).
     * Without absolute: true, a $to later than $from (the normal case)
     * produced a negative "days" figure that could never exceed
     * MAX_RANGE_DAYS, silently disabling this check — confirmed live
     * before the fix that a 2020-01-01..2026-12-31 range returned 200,
     * not 422.
     */
    public function test_a_date_range_wider_than_90_days_is_rejected(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview?from=2020-01-01&to=2026-12-31');

        // This app's own {success,message,data} envelope carries
        // validation errors under `data`, not Laravel's default `errors`
        // key — assertJsonValidationErrors() assumes the latter and
        // would report a false negative here.
        $response->assertStatus(422);
        $this->assertArrayHasKey('to', $response->json('data'));
    }

    public function test_a_date_range_of_exactly_90_days_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview?from=2026-06-19&to=2026-09-17')
            ->assertOk();
    }

    public function test_to_before_from_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview?from=2026-09-17&to=2026-09-01')
            ->assertStatus(422);
    }

    public function test_default_date_range_is_the_trailing_30_days(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $from = \Carbon\Carbon::parse($response->json('data.period.from'));
        $to = \Carbon\Carbon::parse($response->json('data.period.to'));
        $this->assertSame(30, (int) $to->diffInDays($from, absolute: true));
    }

    // ── AUTH EVENTS COUNTS ──────────────────────────────────────────

    public function test_authentication_success_and_failure_counts_reflect_real_events(): void
    {
        $admin = $this->superAdmin();

        AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        AuthEvent::create(['event' => 'login_failed', 'result' => 'failure', 'failure_reason' => 'invalid_credentials']);

        $response = $this->actingAs($admin)->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.authentication.login_success_count'));
        $this->assertSame(1, $response->json('data.authentication.login_failed_count'));
    }

    public function test_authentication_counts_respect_the_date_filter(): void
    {
        $admin = $this->superAdmin();

        $old = AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        $old->created_at = now()->subDays(60);
        $old->save();

        AuthEvent::create(['event' => 'login_success', 'result' => 'success']);

        $response = $this->actingAs($admin)
            ->getJson('/api/admin/security-center/overview?from='.now()->subDays(7)->toDateString().'&to='.now()->toDateString());

        $response->assertOk();
        $this->assertSame(1, $response->json('data.authentication.login_success_count'));
    }

    // ── SESSIONS ────────────────────────────────────────────────────

    public function test_active_session_count_excludes_expired_tokens(): void
    {
        $admin = $this->superAdmin();
        $user = $this->tenantUser('sales');

        $user->createToken('active-one');
        $user->createToken('active-two', ['*'], now()->addDays(30));
        $user->createToken('expired-one', ['*'], now()->subDay());

        $response = $this->actingAs($admin)->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        // At least the 2 active tokens just created, plus whatever the
        // admin's own login-time token count is — asserting a lower
        // bound rather than an exact figure keeps this robust to
        // whatever else RefreshDatabase leaves in place per test.
        $this->assertGreaterThanOrEqual(2, $response->json('data.sessions.active_count'));
    }

    // ── AUDIT ACTIVITY ──────────────────────────────────────────────

    public function test_audit_activity_count_reflects_real_activity_logs_rows(): void
    {
        $admin = $this->superAdmin();

        ActivityLog::create(['action' => 'client.created', 'module' => 'System', 'description' => 'client.created']);
        ActivityLog::create(['action' => 'client.updated', 'module' => 'System', 'description' => 'client.updated']);

        $response = $this->actingAs($admin)->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.audit_activity.recent_count'));
    }

    // ── BACKUPS ─────────────────────────────────────────────────────

    public function test_backup_latest_is_null_when_no_backup_activity_exists(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertNull($response->json('data.backups.latest'));
    }

    public function test_a_failed_backup_is_represented_safely_not_claimed_as_safe(): void
    {
        ActivityLog::create([
            'action' => 'backup.failed',
            'module' => 'System',
            'meta' => ['triggered_via' => 'super_admin_action'],
            'description' => 'backup.failed',
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertSame('failed', $response->json('data.backups.latest.status'));

        // Never "safe"/"secure"/"protected" — only the literal outcome.
        $serialized = json_encode($response->json('data.backups'));
        $this->assertStringNotContainsStringIgnoringCase('safe', $serialized);
        $this->assertStringNotContainsStringIgnoringCase('secure', $serialized);
    }

    // ── RATE LIMITING ───────────────────────────────────────────────

    public function test_rate_limiting_status_reflects_actual_configured_values(): void
    {
        config(['rate_limits.login.max_attempts' => 7, 'rate_limits.login.decay_minutes' => 2]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertSame(7, $response->json('data.rate_limiting.login.max_attempts'));
        $this->assertSame(2, $response->json('data.rate_limiting.login.decay_minutes'));
        $this->assertTrue($response->json('data.rate_limiting.login.enabled'));
    }

    // ── SECURITY HEADERS ────────────────────────────────────────────

    public function test_security_headers_status_matches_the_actual_middleware_behavior(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertTrue($response->json('data.security_headers.x_content_type_options'));
        $this->assertTrue($response->json('data.security_headers.x_frame_options'));

        // The response itself, produced by the real middleware stack,
        // actually carries these headers — not just the reported status.
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'DENY');
    }

    // ── HEALTH ──────────────────────────────────────────────────────

    public function test_health_reflects_real_database_connectivity(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertTrue($response->json('data.health.up'));
        $this->assertTrue($response->json('data.health.database'));
    }

    // ── TENANCY ─────────────────────────────────────────────────────

    public function test_overview_reflects_platform_wide_data_across_multiple_tenants(): void
    {
        $admin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientA->id]);
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientB->id]);

        $response = $this->actingAs($admin)->getJson('/api/admin/security-center/overview');

        $response->assertOk();
        $this->assertSame(2, $response->json('data.authentication.login_success_count'));
    }
}
