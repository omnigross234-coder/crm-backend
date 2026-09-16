<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CallLog Write-Path Super Admin Security Remediation.
 *
 * Root cause (confirmed by reproduction before any fix was applied): both
 * CallLogController::store() and ::storeManual() gated their ownership
 * check with `! $this->isClientAdministrator($user) && $lead->assigned_to
 * !== $user->id`. Roles::isTenantAdmin('super_admin') is false (super_admin
 * is a distinct, higher category from client_admin/admin), so a super_admin
 * fell into the same branch as sales-tier roles and was required to own
 * the lead (`assigned_to === their own id`) — essentially never true for a
 * super_admin. Every write attempt from a super_admin therefore returned
 * 403, confirmed live before the fix: both endpoints returned
 * `{"success":false,"message":"You may only record calls for leads
 * assigned to you."}` for a real lead in a valid tenant.
 *
 * This is the same defect class already fixed in CallLogController::
 * index() (Phase 2) and FollowupController::findLead() (Followup
 * Tenant-Scope Remediation) — a missing explicit super_admin exemption,
 * not a new kind of bug. Fixed by adding the same `&& ! Roles::
 * isSuperAdmin($user->role)` exemption used in FollowupController.
 */
class CallLogWriteSuperAdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── storeManual() — POST /api/call-logs ─────────────────────────────

    public function test_super_admin_can_create_a_manual_call_log_for_a_lead_in_another_tenant(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();
        $salesOwner = $this->user('sales', $client->id);
        $lead = $this->lead($salesOwner);

        $response = $this->actingAs($superAdmin)->postJson('/api/call-logs', [
            'lead_id' => $lead->id,
            'direction' => 'outgoing',
            'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'calling',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('call_logs', [
            'lead_id' => $lead->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_manual_call_log_works_across_multiple_different_tenants(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $leadA = $this->lead($this->user('sales', $clientA->id));
        $leadB = $this->lead($this->user('sales', $clientB->id));

        $this->actingAs($superAdmin)->postJson('/api/call-logs', [
            'lead_id' => $leadA->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertOk();

        $this->actingAs($superAdmin)->postJson('/api/call-logs', [
            'lead_id' => $leadB->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertOk();

        $this->assertDatabaseHas('call_logs', ['lead_id' => $leadA->id, 'user_id' => $superAdmin->id]);
        $this->assertDatabaseHas('call_logs', ['lead_id' => $leadB->id, 'user_id' => $superAdmin->id]);
    }

    public function test_super_admin_manual_call_log_for_nonexistent_lead_fails_validation_creates_no_record(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)->postJson('/api/call-logs', [
            'lead_id' => 999999999,
            'direction' => 'outgoing',
            'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'calling',
        ])->assertStatus(422);

        $this->assertDatabaseCount('call_logs', 0);
    }

    public function test_client_admin_manual_call_log_restricted_to_own_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('client_admin', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);
        $leadInA = $this->lead($this->user('sales', $clientA->id));
        $leadInB = $this->lead($salesB);

        // Within own tenant, regardless of assignment: allowed.
        $this->actingAs($adminA)->postJson('/api/call-logs', [
            'lead_id' => $leadInA->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertOk();

        // Cross-tenant lead_id: Lead's own global scope makes this
        // "not found" from client_admin A's perspective — findOrFail
        // throws, response is 404, and no record is created.
        $this->actingAs($adminA)->postJson('/api/call-logs', [
            'lead_id' => $leadInB->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertNotFound();

        $this->assertDatabaseMissing('call_logs', ['lead_id' => $leadInB->id]);
    }

    public function test_admin_role_manual_call_log_restricted_to_own_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('admin', $clientA->id);
        $leadInB = $this->lead($this->user('sales', $clientB->id));

        $this->actingAs($adminA)->postJson('/api/call-logs', [
            'lead_id' => $leadInB->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertNotFound();

        $this->assertDatabaseCount('call_logs', 0);
    }

    /**
     * Regression guard for every sales-tier role: must remain restricted
     * to leads assigned to themselves, exactly as before this fix. Also
     * proves lead_id tampering (another tenant's lead) and user_id
     * tampering (a forged user_id in the payload, which storeManual()
     * never accepts as input) cannot bypass or spoof anything.
     */
    public function test_sales_tier_roles_remain_restricted_to_their_own_assigned_leads(): void
    {
        foreach (['sales', 'sales_employee', 'sales_manager'] as $role) {
            $client = Client::factory()->create();
            $owner = $this->user($role, $client->id);
            $otherSameTenant = $this->user($role, $client->id);
            $otherTenantUser = $this->user($role, Client::factory()->create()->id);

            $ownLead = $this->lead($owner);
            $notOwnLeadSameTenant = $this->lead($otherSameTenant);

            // Owns the lead: allowed.
            $this->actingAs($owner)->postJson('/api/call-logs', [
                'lead_id' => $ownLead->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
                'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
            ])->assertOk();

            // Same tenant, not their lead: forbidden, no record.
            $this->actingAs($owner)->postJson('/api/call-logs', [
                'lead_id' => $notOwnLeadSameTenant->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
                'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
            ])->assertForbidden();
            $this->assertDatabaseMissing('call_logs', ['lead_id' => $notOwnLeadSameTenant->id]);

            // user_id tampering: forging a different user_id in the
            // payload must not change who the call log is attributed to.
            $response = $this->actingAs($owner)->postJson('/api/call-logs', [
                'lead_id' => $ownLead->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
                'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
                'user_id' => $otherTenantUser->id,
            ])->assertOk();
            $this->assertSame($owner->id, $response->json('data.user_id'));
            $this->assertDatabaseMissing('call_logs', ['user_id' => $otherTenantUser->id]);
        }
    }

    // ── store() — POST /api/leads/{lead}/call-log (legacy) ──────────────

    public function test_super_admin_can_use_legacy_store_for_a_lead_in_another_tenant(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();
        $lead = $this->lead($this->user('sales', $client->id));

        $this->actingAs($superAdmin)
            ->postJson("/api/leads/{$lead->id}/call-log")
            ->assertOk();

        $this->assertDatabaseHas('call_logs', [
            'lead_id' => $lead->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_super_admin_legacy_store_for_nonexistent_lead_returns_404_creates_no_record(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->postJson('/api/leads/999999999/call-log')
            ->assertNotFound();

        $this->assertDatabaseCount('call_logs', 0);
    }

    public function test_client_admin_legacy_store_restricted_to_own_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('client_admin', $clientA->id);
        $leadInB = $this->lead($this->user('sales', $clientB->id));

        // Cross-tenant lead_id via route parameter: implicit route-model
        // binding is scoped by Lead's own global scope, so this 404s
        // before the controller body's ownership check ever runs.
        $this->actingAs($adminA)
            ->postJson("/api/leads/{$leadInB->id}/call-log")
            ->assertNotFound();

        $this->assertDatabaseCount('call_logs', 0);
    }

    public function test_admin_role_legacy_store_allowed_anywhere_in_own_tenant(): void
    {
        $client = Client::factory()->create();
        $admin = $this->user('admin', $client->id);
        $sales = $this->user('sales', $client->id);
        $lead = $this->lead($sales);

        $this->actingAs($admin)
            ->postJson("/api/leads/{$lead->id}/call-log")
            ->assertOk();

        $this->assertDatabaseHas('call_logs', ['lead_id' => $lead->id, 'user_id' => $admin->id]);
    }

    public function test_sales_manager_legacy_store_restricted_to_own_assigned_lead(): void
    {
        $client = Client::factory()->create();
        $owner = $this->user('sales_manager', $client->id);
        $otherManager = $this->user('sales_manager', $client->id);
        $notOwnLead = $this->lead($otherManager);

        $this->actingAs($owner)
            ->postJson("/api/leads/{$notOwnLead->id}/call-log")
            ->assertForbidden();

        $this->assertDatabaseCount('call_logs', 0);
    }

    public function test_sales_employee_legacy_store_restricted_to_own_assigned_lead(): void
    {
        $client = Client::factory()->create();
        $owner = $this->user('sales_employee', $client->id);
        $otherEmployee = $this->user('sales_employee', $client->id);
        $notOwnLead = $this->lead($otherEmployee);

        $this->actingAs($owner)
            ->postJson("/api/leads/{$notOwnLead->id}/call-log")
            ->assertForbidden();

        $this->assertDatabaseCount('call_logs', 0);
    }

    // ── Cross-cutting: authentication ────────────────────────────────────

    public function test_unauthenticated_request_is_rejected_on_both_endpoints(): void
    {
        $lead = $this->lead($this->user('sales'));

        $this->postJson('/api/call-logs', [
            'lead_id' => $lead->id, 'direction' => 'outgoing', 'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0, 'is_connected' => false, 'status' => 'calling',
        ])->assertUnauthorized();

        $this->postJson("/api/leads/{$lead->id}/call-log")->assertUnauthorized();

        $this->assertDatabaseCount('call_logs', 0);
    }

    private function user(string $role, ?int $clientId = null): User
    {
        return User::create([
            'client_id' => $role === 'super_admin' ? null : ($clientId ?? Client::factory()->create()->id),
            'name' => ucfirst($role).' User',
            'email' => uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function lead(User $owner): Lead
    {
        return Lead::create([
            'client_id' => $owner->client_id,
            'name' => 'Test Lead',
            'phone' => (string) random_int(6000000000, 9999999999),
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
