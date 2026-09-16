<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CallLog Update Super Admin Security Remediation.
 *
 * Root cause (confirmed by reproduction before any fix was applied):
 * CallLogController::update()'s tenant-boundary check —
 * `$callLog->user()->where('client_id', $user->client_id)->exists()` —
 * compares the call log owner's client_id against the CALLING user's own
 * client_id. That is correct for every tenant-scoped role, but a
 * super_admin's own client_id is always null, so the comparison is always
 * `client_id = null`, which no real tenant-scoped call log's owner has.
 * Every update attempt from a super_admin against a real call log
 * therefore returned 404 "Call record not found.", confirmed live before
 * the fix (and confirmed to work when the call log's owner artificially
 * also had client_id = null, isolating the exact mechanism). A second,
 * identical-shape defect sits right below it: the ownership check
 * (`! isClientAdministrator($user) && $callLog->user_id !== $user->id`)
 * also does not exempt super_admin, so clearing the first check alone
 * would only have changed the symptom from 404 to 403.
 *
 * Same defect class already fixed in CallLogController::index(),
 * ::store(), ::storeManual(), and FollowupController::findLead() — a
 * missing explicit super_admin exemption. Fixed by exempting super_admin
 * from both checks, matching CallLogController::index()'s existing
 * super_admin branch and LeadController::scopeLeadsForUser()'s hierarchy.
 */
class CallLogUpdateSuperAdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    // ── Super Admin ──────────────────────────────────────────────────────

    public function test_super_admin_can_update_a_call_log_owned_by_a_real_tenant_user(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();
        $sales = $this->user('sales', $client->id);
        $lead = $this->lead($sales);
        $callLog = $this->callingCallLog($lead, $sales);

        $response = $this->actingAs($superAdmin)->patchJson("/api/call-logs/{$callLog->id}", [
            'duration_seconds' => 120,
            'is_connected' => true,
            'status' => 'completed',
            'ended_at' => now()->addSeconds(120)->toIso8601String(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.duration_seconds', 120);

        $this->assertSame('completed', $callLog->fresh()->status);
    }

    public function test_super_admin_can_update_call_logs_across_multiple_different_tenants(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $salesA = $this->user('sales', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);
        $callLogA = $this->callingCallLog($this->lead($salesA), $salesA);
        $callLogB = $this->callingCallLog($this->lead($salesB), $salesB);

        $this->actingAs($superAdmin)->patchJson("/api/call-logs/{$callLogA->id}", [
            'duration_seconds' => 30, 'is_connected' => true, 'status' => 'completed',
            'ended_at' => now()->addSeconds(30)->toIso8601String(),
        ])->assertOk();

        $this->actingAs($superAdmin)->patchJson("/api/call-logs/{$callLogB->id}", [
            'duration_seconds' => 45, 'is_connected' => true, 'status' => 'completed',
            'ended_at' => now()->addSeconds(45)->toIso8601String(),
        ])->assertOk();

        $this->assertSame('completed', $callLogA->fresh()->status);
        $this->assertSame('completed', $callLogB->fresh()->status);
    }

    public function test_super_admin_update_of_nonexistent_call_log_returns_404(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->patchJson('/api/call-logs/999999999', [
                'duration_seconds' => 10,
                'is_connected' => true,
                'status' => 'completed',
                'ended_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();
    }

    // ── Tenant administrators (regression guards) ───────────────────────

    public function test_client_admin_cannot_update_a_call_log_in_another_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('client_admin', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);
        $callLogB = $this->callingCallLog($this->lead($salesB), $salesB);

        $this->actingAs($adminA)
            ->patchJson("/api/call-logs/{$callLogB->id}", [
                'duration_seconds' => 10, 'is_connected' => true, 'status' => 'completed',
                'ended_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();

        $this->assertSame('calling', $callLogB->fresh()->status);
    }

    public function test_admin_role_cannot_update_a_call_log_in_another_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('admin', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);
        $callLogB = $this->callingCallLog($this->lead($salesB), $salesB);

        $this->actingAs($adminA)
            ->patchJson("/api/call-logs/{$callLogB->id}", [
                'duration_seconds' => 10, 'is_connected' => true, 'status' => 'completed',
                'ended_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();

        $this->assertSame('calling', $callLogB->fresh()->status);
    }

    public function test_client_admin_can_update_any_call_log_within_their_own_tenant(): void
    {
        $client = Client::factory()->create();
        $admin = $this->user('client_admin', $client->id);
        $sales = $this->user('sales', $client->id);
        $callLog = $this->callingCallLog($this->lead($sales), $sales);

        $this->actingAs($admin)
            ->patchJson("/api/call-logs/{$callLog->id}", [
                'duration_seconds' => 15, 'is_connected' => true, 'status' => 'completed',
                'ended_at' => now()->addSeconds(15)->toIso8601String(),
            ])
            ->assertOk();

        $this->assertSame('completed', $callLog->fresh()->status);
    }

    // ── Sales-tier roles (regression guards) ────────────────────────────

    public function test_sales_tier_roles_remain_restricted_to_updating_their_own_call_logs(): void
    {
        foreach (['sales', 'sales_employee', 'sales_manager'] as $role) {
            $client = Client::factory()->create();
            $owner = $this->user($role, $client->id);
            $otherSameTenant = $this->user($role, $client->id);
            $ownCallLog = $this->callingCallLog($this->lead($owner), $owner);
            $notOwnCallLog = $this->callingCallLog($this->lead($otherSameTenant), $otherSameTenant);

            // Owns the record: allowed.
            $this->actingAs($owner)
                ->patchJson("/api/call-logs/{$ownCallLog->id}", [
                    'duration_seconds' => 20, 'is_connected' => true, 'status' => 'completed',
                    'ended_at' => now()->addSeconds(20)->toIso8601String(),
                ])
                ->assertOk();
            $this->assertSame('completed', $ownCallLog->fresh()->status);

            // Same tenant, not their own record: forbidden, unchanged.
            $this->actingAs($owner)
                ->patchJson("/api/call-logs/{$notOwnCallLog->id}", [
                    'duration_seconds' => 20, 'is_connected' => true, 'status' => 'completed',
                    'ended_at' => now()->addSeconds(20)->toIso8601String(),
                ])
                ->assertForbidden();
            $this->assertSame('calling', $notOwnCallLog->fresh()->status);
        }
    }

    // ── Tampering ─────────────────────────────────────────────────────────

    /**
     * update() does not accept lead_id or user_id as input at all (its
     * validation rules only permit duration_seconds, is_connected, status,
     * ended_at, android_call_log_id, sms_sent, whatsapp_sent) — forging
     * them in the payload must have no effect on which record is
     * identified or who it is attributed to.
     */
    public function test_forged_lead_id_and_user_id_in_payload_have_no_effect(): void
    {
        $client = Client::factory()->create();
        $owner = $this->user('sales', $client->id);
        $callLog = $this->callingCallLog($this->lead($owner), $owner);
        $otherUser = $this->user('sales', $client->id);
        $otherLead = $this->lead($otherUser);

        $response = $this->actingAs($owner)
            ->patchJson("/api/call-logs/{$callLog->id}", [
                'duration_seconds' => 25, 'is_connected' => true, 'status' => 'completed',
                'ended_at' => now()->addSeconds(25)->toIso8601String(),
                'lead_id' => $otherLead->id,
                'user_id' => $otherUser->id,
            ])
            ->assertOk();

        $fresh = $callLog->fresh();
        $this->assertSame($callLog->lead_id, $fresh->lead_id);
        $this->assertSame($owner->id, $fresh->user_id);
        $this->assertSame($response->json('data.lead_id'), $fresh->lead_id);
        $this->assertSame($response->json('data.user_id'), $fresh->user_id);
    }

    /**
     * Route call_log_id tampering: guessing another tenant's call log ID
     * must not be resolvable by a non-super-admin, and must create no
     * change to that record.
     */
    public function test_call_log_id_tampering_across_tenants_is_rejected(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $salesA = $this->user('sales', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);
        $callLogB = $this->callingCallLog($this->lead($salesB), $salesB);

        $this->actingAs($salesA)
            ->patchJson("/api/call-logs/{$callLogB->id}", [
                'duration_seconds' => 5, 'is_connected' => true, 'status' => 'completed',
                'ended_at' => now()->toIso8601String(),
            ])
            ->assertNotFound();

        $this->assertSame('calling', $callLogB->fresh()->status);
    }

    // ── Authentication ────────────────────────────────────────────────────

    public function test_unauthenticated_request_is_rejected(): void
    {
        $client = Client::factory()->create();
        $sales = $this->user('sales', $client->id);
        $callLog = $this->callingCallLog($this->lead($sales), $sales);

        $this->patchJson("/api/call-logs/{$callLog->id}", [
            'duration_seconds' => 5, 'is_connected' => true, 'status' => 'completed',
            'ended_at' => now()->toIso8601String(),
        ])->assertUnauthorized();

        $this->assertSame('calling', $callLog->fresh()->status);
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

    private function callingCallLog(Lead $lead, User $owner): CallLog
    {
        return CallLog::create([
            'user_id' => $owner->id,
            'lead_id' => $lead->id,
            'direction' => 'outgoing',
            'called_at' => now(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'calling',
        ]);
    }
}
