<?php

namespace Tests\Feature;

use App\Models\CallLog;
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
        $owner = $this->user('sales');
        $otherSales = $this->user('sales');
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
        $owner = $this->user('sales');
        $otherSales = $this->user('sales');
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

    public function test_call_report_remains_admin_only(): void
    {
        $this->actingAs($this->user('sales'))
            ->getJson('/api/call-logs')
            ->assertForbidden();

        $this->actingAs($this->user('admin'))
            ->getJson('/api/call-logs')
            ->assertOk();
    }

    private function user(string $role): User
    {
        return User::create([
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
            'name' => 'Amit Sharma',
            'phone' => '9876543210',
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'assigned_to' => $owner->id,
            'created_by' => $owner->id,
        ]);
    }
}
