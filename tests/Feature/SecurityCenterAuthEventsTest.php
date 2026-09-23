<?php

namespace Tests\Feature;

use App\Models\AuthEvent;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Super Admin Security Center, Phase 1: GET /api/admin/security-center/auth-events.
 *
 * Mirrors AuditLogController's own proven pattern (allowlisted sort,
 * validated filters, bounded pagination) — this endpoint exists because
 * auth_events (Workstream C) had no reader at all before this workstream.
 */
class SecurityCenterAuthEventsTest extends TestCase
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

    public function test_super_admin_can_access_auth_events(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events')
            ->assertOk();
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $this->getJson('/api/admin/security-center/auth-events')->assertStatus(401);
    }

    public function test_client_admin_is_denied(): void
    {
        $this->actingAs($this->tenantUser('client_admin'))
            ->getJson('/api/admin/security-center/auth-events')
            ->assertStatus(403);
    }

    public function test_admin_is_denied(): void
    {
        $this->actingAs($this->tenantUser('admin'))
            ->getJson('/api/admin/security-center/auth-events')
            ->assertStatus(403);
    }

    public function test_sales_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales'))
            ->getJson('/api/admin/security-center/auth-events')
            ->assertStatus(403);
    }

    public function test_sales_employee_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales_employee'))
            ->getJson('/api/admin/security-center/auth-events')
            ->assertStatus(403);
    }

    public function test_sales_manager_is_denied(): void
    {
        $this->actingAs($this->tenantUser('sales_manager'))
            ->getJson('/api/admin/security-center/auth-events')
            ->assertStatus(403);
    }

    // ── DATA SAFETY ─────────────────────────────────────────────────

    public function test_response_contains_no_secret_bearing_fields(): void
    {
        $client = Client::factory()->create();
        $user = User::create([
            'client_id' => $client->id, 'name' => 'X', 'email' => 'sc-evt@example.com',
            'password' => 'password', 'role' => 'sales', 'status' => 'active',
        ]);
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'user_id' => $user->id, 'client_id' => $client->id]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events');

        $response->assertOk();
        $row = $response->json('data.0');

        $this->assertEqualsCanonicalizing(
            ['id', 'event', 'result', 'failure_reason', 'login_identifier', 'ip_address', 'user', 'client', 'created_at'],
            array_keys($row)
        );

        $serialized = json_encode($response->json());
        foreach (['"token"', '"password"', '"secret"', 'Authorization'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $serialized);
        }
    }

    // ── FILTERS ─────────────────────────────────────────────────────

    public function test_valid_event_filter_works(): void
    {
        AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        AuthEvent::create(['event' => 'login_failed', 'result' => 'failure']);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?event=login_failed');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('login_failed', $response->json('data.0.event'));
    }

    public function test_invalid_event_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?event=not_a_real_event')
            ->assertStatus(422);
    }

    public function test_valid_result_filter_works(): void
    {
        AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        AuthEvent::create(['event' => 'login_failed', 'result' => 'failure']);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?result=failure');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_invalid_result_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?result=maybe')
            ->assertStatus(422);
    }

    public function test_a_date_range_wider_than_90_days_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?from=2020-01-01&to=2026-12-31')
            ->assertStatus(422);
    }

    public function test_valid_allowlisted_sort_works(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?sort_by=event&sort_dir=asc')
            ->assertOk();
    }

    public function test_invalid_sort_column_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?sort_by=login_identifier')
            ->assertStatus(422);
    }

    public function test_invalid_sort_direction_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?sort_dir=sideways')
            ->assertStatus(422);
    }

    public function test_pagination_respects_per_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            AuthEvent::create(['event' => 'login_success', 'result' => 'success']);
        }

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?per_page=2');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(5, $response->json('meta.total'));
    }

    public function test_per_page_above_the_maximum_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?per_page=101')
            ->assertStatus(422);
    }

    public function test_per_page_at_the_maximum_is_accepted(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?per_page=100')
            ->assertOk();
    }

    // ── TENANCY ─────────────────────────────────────────────────────

    public function test_super_admin_sees_events_across_multiple_tenants(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientA->id]);
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientB->id]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events');

        $response->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_client_id_filter_narrows_but_never_grants_access(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientA->id]);
        AuthEvent::create(['event' => 'login_success', 'result' => 'success', 'client_id' => $clientB->id]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?client_id='.$clientA->id);

        $response->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($clientA->id, $response->json('data.0.client.id'));
    }

    public function test_invalid_client_id_is_rejected(): void
    {
        $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?client_id=99999999')
            ->assertStatus(422);
    }

    // ── ATTRIBUTION ─────────────────────────────────────────────────

    public function test_unknown_user_events_show_null_attribution_not_fabricated_data(): void
    {
        AuthEvent::create([
            'event' => 'login_failed',
            'result' => 'failure',
            'failure_reason' => 'invalid_credentials',
            'login_identifier' => 'nobody@example.com',
            'user_id' => null,
            'client_id' => null,
        ]);

        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events');

        $response->assertOk();
        $this->assertNull($response->json('data.0.user'));
        $this->assertNull($response->json('data.0.client'));
        $this->assertSame('nobody@example.com', $response->json('data.0.login_identifier'));
    }

    // ── ERROR HANDLING ──────────────────────────────────────────────

    public function test_malformed_request_produces_a_controlled_validation_error_not_a_raw_exception(): void
    {
        $response = $this->actingAs($this->superAdmin())
            ->getJson('/api/admin/security-center/auth-events?per_page=not-a-number');

        $response->assertStatus(422);
        $this->assertArrayHasKey('data', $response->json());
        $this->assertStringNotContainsString('Exception', $response->getContent());
        $this->assertStringNotContainsString('.php', $response->getContent());
    }
}
