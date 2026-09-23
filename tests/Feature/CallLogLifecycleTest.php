<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallLogLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_user_can_create_and_complete_own_call(): void
    {
        $sales = $this->user('sales');
        $lead = $this->lead($sales);

        $created = $this->actingAs($sales)->postJson('/api/call-logs', [
            'lead_id' => $lead->id,
            'direction' => 'outgoing',
            'called_at' => '2026-06-12T10:00:05+05:30',
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'calling',
        ]);

        $created->assertOk()
            ->assertJsonPath('data.status', 'calling')
            ->assertJsonPath('data.direction', 'outgoing')
            ->assertJsonPath('data.user_id', $sales->id);

        $callLogId = $created->json('data.id');

        $this->actingAs($sales)
            ->withHeader('X-HTTP-Method-Override', 'PATCH')
            ->postJson("/api/call-logs/{$callLogId}", [
                'duration_seconds' => 195,
                'is_connected' => true,
                'status' => 'completed',
                'ended_at' => '2026-06-12T10:03:20+05:30',
                'android_call_log_id' => 42,
                'sms_sent' => true,
                'whatsapp_sent' => false,
            ])->assertOk()
            ->assertJsonPath('data.duration_seconds', 195)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.android_call_log_id', '42')
            ->assertJsonPath('data.sms_sent', true);
    }

    public function test_sales_user_can_record_matched_incoming_call_once(): void
    {
        $sales = $this->user('sales');
        $lead = $this->lead($sales);
        $payload = [
            'lead_id' => $lead->id,
            'direction' => 'incoming',
            'called_at' => '2026-07-20T10:30:00+05:30',
            'ended_at' => '2026-07-20T10:32:15+05:30',
            'duration_seconds' => 135,
            'is_connected' => true,
            'status' => 'completed',
            'android_call_log_id' => 'incoming-123',
        ];

        $this->actingAs($sales)->postJson('/api/call-logs', $payload)
            ->assertOk()
            ->assertJsonPath('data.direction', 'incoming')
            ->assertJsonPath('data.duration_seconds', 135);

        $this->actingAs($sales)->postJson('/api/call-logs', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Call was already recorded.');

        $this->assertDatabaseCount('call_logs', 1);
    }

    public function test_sales_user_can_record_missed_incoming_call(): void
    {
        $sales = $this->user('sales');
        $lead = $this->lead($sales);

        $this->actingAs($sales)->postJson('/api/call-logs', [
            'lead_id' => $lead->id,
            'direction' => 'incoming',
            'called_at' => '2026-07-20T10:30:00+05:30',
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'missed',
            'android_call_log_id' => 'incoming-124',
        ])->assertOk()->assertJsonPath('data.status', 'missed');
    }

    public function test_sales_user_cannot_record_call_for_unassigned_lead(): void
    {
        $client = Client::factory()->create();
        $owner = $this->user('sales', $client->id);
        $otherSales = $this->user('sales', $client->id);
        $lead = $this->lead($owner);

        $this->actingAs($otherSales)->postJson('/api/call-logs', [
            'lead_id' => $lead->id,
            'direction' => 'incoming',
            'called_at' => now()->toIso8601String(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'missed',
            'android_call_log_id' => 'incoming-125',
        ])->assertForbidden();
    }

    public function test_sales_user_cannot_update_another_users_call(): void
    {
        $client = Client::factory()->create();
        $owner = $this->user('sales', $client->id);
        $otherSales = $this->user('sales', $client->id);
        $lead = $this->lead($owner);
        $callLog = CallLog::create([
            'user_id' => $owner->id,
            'lead_id' => $lead->id,
            'called_at' => now(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'status' => 'calling',
        ]);

        $this->actingAs($otherSales)->patchJson("/api/call-logs/{$callLog->id}", [
            'duration_seconds' => 10,
            'is_connected' => true,
            'status' => 'completed',
            'ended_at' => now()->addSeconds(10)->toIso8601String(),
        ])->assertForbidden();
    }

    /**
     * GET /api/call-logs carries no role middleware and CallLogController::index()
     * never rejects a non-admin caller — it narrows the *results* instead
     * (isClientAdministrator() gates only the optional ?user_id filter). This
     * test previously asserted a 403-for-sales restriction that the endpoint
     * has never actually implemented, and which was masked for a long time
     * by an unrelated bug (test users had no tenant, so every call died in
     * middleware before ever reaching this endpoint). Replaced with a test
     * of the real, current behavior: everyone gets 200, but a non-admin only
     * ever sees their own call logs while a tenant admin sees everyone's.
     */
    public function test_call_report_scopes_results_to_own_calls_for_non_admins(): void
    {
        $client = Client::factory()->create();
        $sales = $this->user('sales', $client->id);
        $otherSales = $this->user('sales', $client->id);
        $admin = $this->user('admin', $client->id);

        CallLog::create([
            'user_id' => $sales->id,
            'lead_id' => $this->lead($sales, '9876543210')->id,
            'called_at' => now(),
            'duration_seconds' => 30,
            'is_connected' => true,
            'status' => 'completed',
        ]);
        CallLog::create([
            'user_id' => $otherSales->id,
            // Same tenant as $sales's lead above, so this needs a distinct
            // phone number now that (client_id, phone) is a genuine unique
            // constraint (Workstream 12 finding W12-F01) — the two leads
            // are otherwise unrelated to what this test actually checks.
            'lead_id' => $this->lead($otherSales, '9876543211')->id,
            'called_at' => now(),
            'duration_seconds' => 45,
            'is_connected' => true,
            'status' => 'completed',
        ]);

        $this->actingAs($sales)
            ->getJson('/api/call-logs')
            ->assertOk()
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.user_id', $sales->id);

        $this->actingAs($admin)
            ->getJson('/api/call-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data.logs');
    }

    /**
     * Phase 2 Foundation fix: index() previously always filtered to
     * `whereHas('user', client_id = caller's client_id)`, which is null
     * for a super_admin — so this endpoint silently returned an
     * empty/near-empty result instead of the platform-wide visibility
     * every other admin surface already grants super_admin.
     */
    public function test_super_admin_sees_call_logs_across_all_tenants(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $superAdmin = $this->user('super_admin', null);
        $salesA = $this->user('sales', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);

        CallLog::create([
            'user_id' => $salesA->id,
            'lead_id' => $this->lead($salesA, '9000000001')->id,
            'called_at' => now(),
            'duration_seconds' => 30,
            'is_connected' => true,
            'status' => 'completed',
        ]);
        CallLog::create([
            'user_id' => $salesB->id,
            'lead_id' => $this->lead($salesB, '9000000002')->id,
            'called_at' => now(),
            'duration_seconds' => 45,
            'is_connected' => true,
            'status' => 'completed',
        ]);

        $this->actingAs($superAdmin)
            ->getJson('/api/call-logs')
            ->assertOk()
            ->assertJsonCount(2, 'data.logs');
    }

    public function test_super_admin_can_filter_by_client_id(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $superAdmin = $this->user('super_admin', null);
        $salesA = $this->user('sales', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);

        CallLog::create([
            'user_id' => $salesA->id,
            'lead_id' => $this->lead($salesA, '9000000003')->id,
            'called_at' => now(),
            'duration_seconds' => 30,
            'is_connected' => true,
            'status' => 'completed',
        ]);
        CallLog::create([
            'user_id' => $salesB->id,
            'lead_id' => $this->lead($salesB, '9000000004')->id,
            'called_at' => now(),
            'duration_seconds' => 45,
            'is_connected' => true,
            'status' => 'completed',
        ]);

        $this->actingAs($superAdmin)
            ->getJson("/api/call-logs?client_id={$clientA->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data.logs')
            ->assertJsonPath('data.logs.0.user_id', $salesA->id);
    }

    /**
     * ID tampering: a super_admin passing a nonexistent client_id must be
     * rejected outright, never silently ignored or treated as "match nothing
     * therefore show everything."
     */
    public function test_super_admin_client_id_filter_rejects_nonexistent_client(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->getJson('/api/call-logs?client_id=999999')
            ->assertStatus(422);
    }

    /**
     * A client_admin cannot use the new super_admin-only ?client_id= param
     * to escape their own tenant scope — it must only ever apply on the
     * super_admin branch.
     */
    public function test_client_admin_cannot_use_client_id_param_to_see_another_tenant(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $adminA = $this->user('client_admin', $clientA->id);
        $salesB = $this->user('sales', $clientB->id);

        CallLog::create([
            'user_id' => $salesB->id,
            'lead_id' => $this->lead($salesB, '9000000005')->id,
            'called_at' => now(),
            'duration_seconds' => 45,
            'is_connected' => true,
            'status' => 'completed',
        ]);

        $this->actingAs($adminA)
            ->getJson("/api/call-logs?client_id={$clientB->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.logs');
    }

    private function user(string $role, ?int $clientId = null): User
    {
        return User::create([
            'client_id' => $clientId ?? Client::factory()->create()->id,
            'name' => ucfirst($role).' User',
            'email' => uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function lead(User $owner, string $phone = '9876543210'): Lead
    {
        return Lead::create([
            'client_id' => $owner->client_id,
            'name' => 'Amit Sharma',
            'phone' => $phone,
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
