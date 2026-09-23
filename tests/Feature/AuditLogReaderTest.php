<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 Foundation — Super Admin audit-log reader (AuditLogController).
 * The activity_logs table/writer already existed; this is the new read path.
 */
class AuditLogReaderTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_audit_logs_across_tenants(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $this->activityLog($clientA->id, 'client.created', 'Client');
        $this->activityLog($clientB->id, 'client.created', 'Client');

        $response = $this->actingAs($superAdmin)->getJson('/api/audit-logs')->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_client_admin_cannot_read_audit_logs(): void
    {
        $client = Client::factory()->create();
        $clientAdmin = $this->user('client_admin', $client->id);

        $this->actingAs($clientAdmin)->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_sales_cannot_read_audit_logs(): void
    {
        $client = Client::factory()->create();
        $sales = $this->user('sales', $client->id);

        $this->actingAs($sales)->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_admin_role_cannot_read_audit_logs(): void
    {
        // 'admin' is a tenant-scoped role, not super_admin — must not be
        // able to read the platform-wide audit log.
        $client = Client::factory()->create();
        $admin = $this->user('admin', $client->id);

        $this->actingAs($admin)->getJson('/api/audit-logs')->assertForbidden();
    }

    public function test_pagination_is_applied_and_bounded(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->activityLog($client->id, 'client.updated', 'Client');
        }

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?per_page=2')
            ->assertOk();

        $response->assertJsonCount(2, 'data');
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(3, $response->json('meta.last_page'));
    }

    public function test_invalid_per_page_is_rejected(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?per_page=500')
            ->assertStatus(422);

        $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?per_page=0')
            ->assertStatus(422);
    }

    public function test_sort_by_rejects_non_allowlisted_column(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?sort_by=password')
            ->assertStatus(422);
    }

    public function test_filters_by_client_and_action_and_resource(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $this->activityLog($clientA->id, 'client.created', 'Client', \App\Models\Client::class);
        $leadOwner = $this->user('sales', $clientA->id);
        $lead = Lead::create([
            'client_id' => $clientA->id,
            'name' => 'Amit Sharma',
            'phone' => '9876543210',
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'assigned_to' => $leadOwner->id,
            'created_by' => $leadOwner->id,
        ]);
        $this->activityLog($clientA->id, 'lead.created', 'Lead', Lead::class, $lead->id);
        $this->activityLog($clientB->id, 'client.created', 'Client', \App\Models\Client::class);

        $byClient = $this->actingAs($superAdmin)
            ->getJson("/api/audit-logs?client_id={$clientA->id}")
            ->assertOk();
        $this->assertSame(2, $byClient->json('meta.total'));

        $byResource = $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?resource=lead')
            ->assertOk();
        $this->assertSame(1, $byResource->json('meta.total'));
        $this->assertSame('lead.created', $byResource->json('data.0.action'));
    }

    public function test_invalid_client_id_filter_is_rejected(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)
            ->getJson('/api/audit-logs?client_id=999999')
            ->assertStatus(422);
    }

    public function test_sensitive_meta_keys_are_redacted(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();

        ActivityLog::create([
            'client_id' => $client->id,
            'user_id' => null,
            'action' => 'user.password_reset',
            'subject_type' => null,
            'subject_id' => null,
            'meta' => ['token' => 'super-secret-value', 'note' => 'reset requested'],
            'module' => 'System',
            'record_id' => null,
            'description' => 'user.password_reset',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($superAdmin)->getJson('/api/audit-logs')->assertOk();

        $this->assertSame('[redacted]', $response->json('data.0.meta.token'));
        $this->assertSame('reset requested', $response->json('data.0.meta.note'));
    }

    private function user(string $role, ?int $clientId): User
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

    private function activityLog(
        int $clientId,
        string $action,
        string $module,
        ?string $subjectType = null,
        ?int $subjectId = null
    ): ActivityLog {
        return ActivityLog::create([
            'client_id' => $clientId,
            'user_id' => null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'meta' => [],
            'module' => $module,
            'record_id' => $subjectId,
            'description' => $action,
            'ip_address' => '127.0.0.1',
        ]);
    }
}
