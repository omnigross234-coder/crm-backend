<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\Followup;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 5 (Tenant Management Control Center) — GET /api/admin/tenants,
 * GET /api/admin/tenants/{client}.
 */
class SuperAdminTenantTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_list_tenants_with_enriched_data(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create(['name' => 'Acme Corp']);
        $admin = $this->user('client_admin', $client->id, 'owner@acme.test');
        $plan = $this->plan();
        $this->subscription($client, $plan, 'active');
        $this->lead($client, $admin);
        $this->lead($client, $admin);
        $this->lead($client, $admin);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/tenants')->assertOk();

        $row = collect($response->json('data'))->firstWhere('id', $client->id);
        $this->assertSame(1, $row['users_count']);
        $this->assertSame(3, $row['leads_count']);
        $this->assertSame('owner@acme.test', $row['admin']['email']);
        $this->assertSame('active', $row['subscription']['status']);
        $this->assertSame($plan->id, $row['subscription']['plan']['id']);
    }

    public function test_tenant_list_is_paginated_not_fully_loaded(): void
    {
        $superAdmin = $this->user('super_admin', null);
        Client::factory()->count(5)->create();

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/tenants?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
        $this->assertGreaterThanOrEqual(5, $response->json('meta.total'));
    }

    public function test_tenant_list_search_matches_name_slug_or_admin_email(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create(['name' => 'Zenith Traders']);
        $this->user('client_admin', $client->id, 'zenith-owner@example.com');
        Client::factory()->create(['name' => 'Unrelated Co']);

        $byName = $this->actingAs($superAdmin)->getJson('/api/admin/tenants?search=Zenith')->assertOk();
        $this->assertCount(1, $byName->json('data'));

        $byEmail = $this->actingAs($superAdmin)->getJson('/api/admin/tenants?search=zenith-owner')->assertOk();
        $this->assertCount(1, $byEmail->json('data'));
    }

    public function test_tenant_list_filters_by_status(): void
    {
        $superAdmin = $this->user('super_admin', null);
        Client::factory()->create(['status' => 'active', 'name' => 'Active Co']);
        Client::factory()->create(['status' => 'suspended', 'name' => 'Suspended Co']);

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/tenants?status=suspended')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Suspended Co'));
        $this->assertFalse($names->contains('Active Co'));
    }

    public function test_tenant_list_filters_by_subscription_status_none(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $withSub = Client::factory()->create(['name' => 'Has Subscription']);
        $this->subscription($withSub, $this->plan(), 'active');
        Client::factory()->create(['name' => 'No Subscription']);

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/tenants?subscription_status=none')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('No Subscription'));
        $this->assertFalse($names->contains('Has Subscription'));
    }

    public function test_tenant_list_filters_by_trial_only(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $trialClient = Client::factory()->create(['name' => 'Trial Co']);
        $this->subscription($trialClient, $this->plan(), 'trial');
        $activeClient = Client::factory()->create(['name' => 'Active Co']);
        $this->subscription($activeClient, $this->plan(), 'active');

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/tenants?trial_only=1')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Trial Co'));
        $this->assertFalse($names->contains('Active Co'));
    }

    public function test_tenant_list_pagination_out_of_range_page_returns_empty_not_error(): void
    {
        $superAdmin = $this->user('super_admin', null);
        Client::factory()->count(2)->create();

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/tenants?page=999')
            ->assertOk();

        $this->assertSame([], $response->json('data'));
    }

    /**
     * A data-backfill migration (add_client_id_to_leads_table) always
     * seeds one "Default Client" row on a fresh test database, so the true
     * empty-platform baseline isn't 0 — the endpoint must still respond
     * correctly (no crash, well-formed pagination) with just that row.
     */
    public function test_tenant_list_handles_near_empty_platform_gracefully(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/tenants')->assertOk();

        $this->assertSame(Client::count(), $response->json('meta.total'));
        $this->assertIsArray($response->json('data'));
    }

    public function test_client_admin_cannot_access_tenant_list(): void
    {
        $client = Client::factory()->create();
        $clientAdmin = $this->user('client_admin', $client->id);

        $this->actingAs($clientAdmin)->getJson('/api/admin/tenants')->assertForbidden();
        $this->actingAs($clientAdmin)->getJson("/api/admin/tenants/{$client->id}")->assertForbidden();
    }

    public function test_sales_cannot_access_tenant_list_or_detail(): void
    {
        $client = Client::factory()->create();
        $sales = $this->user('sales', $client->id);

        $this->actingAs($sales)->getJson('/api/admin/tenants')->assertForbidden();
        $this->actingAs($sales)->getJson("/api/admin/tenants/{$client->id}")->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/tenants')->assertUnauthorized();
    }

    /**
     * Tenant isolation / ID tampering: a client_admin of one tenant must
     * not be able to view another tenant's detail by guessing/incrementing
     * the numeric ID in the URL, even though the route itself is
     * super_admin-gated (defense in depth — same class of test the brief's
     * §2/§15 explicitly requires).
     */
    public function test_client_admin_cannot_view_another_tenants_detail_via_id_tampering(): void
    {
        $ownClient = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $clientAdmin = $this->user('client_admin', $ownClient->id);

        $this->actingAs($clientAdmin)
            ->getJson("/api/admin/tenants/{$otherClient->id}")
            ->assertForbidden();
    }

    public function test_nonexistent_tenant_id_returns_404_not_a_500(): void
    {
        $superAdmin = $this->user('super_admin', null);

        $this->actingAs($superAdmin)->getJson('/api/admin/tenants/999999')->assertNotFound();
    }

    public function test_tenant_detail_returns_users_crm_billing_and_operations_sections(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();
        $admin = $this->user('client_admin', $client->id, 'admin@tenant.test');
        $this->user('sales', $client->id);
        $this->user('sales', $client->id, null, 'inactive');
        $lead = $this->lead($client, $admin);
        $plan = $this->plan();
        $subscription = $this->subscription($client, $plan, 'active');
        $this->payment($subscription, 499);

        $salesUser = User::where('client_id', $client->id)->where('role', 'sales')->where('status', 'active')->first();
        CallLog::create([
            'user_id' => $salesUser->id,
            'lead_id' => $lead->id,
            'direction' => 'outgoing',
            'called_at' => now(),
            'status' => 'completed',
        ]);
        Followup::create([
            'lead_id' => $lead->id,
            'user_id' => $salesUser->id,
            'note' => 'Follow up next week',
            'next_followup_date' => now()->addDays(3),
            'status' => 'pending',
        ]);

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/admin/tenants/{$client->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame(3, $data['users']['total']);
        $this->assertSame(2, $data['users']['active']);
        $this->assertSame(1, $data['users']['inactive']);
        $this->assertSame(1, $data['crm']['leads_count']);
        $this->assertSame(1, $data['crm']['calls_last_30d']);
        $this->assertSame(1, $data['crm']['followups_last_30d']);
        $this->assertSame('active', $data['billing']['active_subscription']['status']);
        $this->assertCount(1, $data['billing']['recent_payments']);
        $this->assertFalse($data['operations']['per_tenant_backups_supported']);
    }

    public function test_tenant_detail_handles_tenant_with_no_subscription_or_users(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/admin/tenants/{$client->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertSame(0, $data['users']['total']);
        $this->assertNull($data['admin']);
        $this->assertNull($data['billing']['active_subscription']);
        $this->assertNull($data['billing']['latest_subscription']);
    }

    /**
     * Billing relationship integrity: a cancelled/expired subscription must
     * still surface via `latest_subscription` even though it's outside
     * Client::activeSubscription()'s trial/active scope — otherwise a
     * lapsed tenant would misleadingly look like it never subscribed.
     */
    public function test_tenant_detail_shows_latest_subscription_even_when_not_active(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();
        $plan = $this->plan();
        $this->subscription($client, $plan, 'cancelled');

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/admin/tenants/{$client->id}")
            ->assertOk();

        $data = $response->json('data');
        $this->assertNull($data['billing']['active_subscription']);
        $this->assertSame('cancelled', $data['billing']['latest_subscription']['status']);
    }

    /**
     * Confirms the AuditLogController `subject_id` addition: a
     * super_admin-initiated client.updated action logs with client_id=null
     * (ActivityLogger records the ACTOR's client_id) but a real subject_id,
     * so resource+subject_id must find it even though client_id can't.
     */
    public function test_audit_log_subject_id_filter_finds_super_admin_initiated_tenant_events(): void
    {
        $superAdmin = $this->user('super_admin', null);
        $client = Client::factory()->create();

        ActivityLog::create([
            'client_id' => null,
            'user_id' => $superAdmin->id,
            'action' => 'client.updated',
            'subject_type' => Client::class,
            'subject_id' => $client->id,
            'module' => 'Client',
            'record_id' => $client->id,
            'description' => 'client.updated',
        ]);

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/audit-logs?resource=client&subject_id={$client->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('client.updated', $response->json('data.0.action'));
    }

    private function user(string $role, ?int $clientId, ?string $email = null, string $status = 'active'): User
    {
        return User::create([
            'client_id' => $clientId,
            'name' => ucfirst($role).' User',
            'email' => $email ?? uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => $status,
        ]);
    }

    private function plan(float $price = 999): Plan
    {
        return Plan::create([
            'name' => 'Test Plan',
            'slug' => 'test-plan-'.uniqid(),
            'price' => $price,
            'billing_cycle' => 'monthly',
            'seat_limit' => 5,
            'features' => ['leads' => true],
            'status' => 'active',
        ]);
    }

    private function subscription(Client $client, Plan $plan, string $status): Subscription
    {
        return Subscription::create([
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'status' => $status,
            'trial_ends_at' => $status === 'trial' ? now()->addDays(14) : null,
            'current_period_start' => now(),
            'current_period_end' => now()->addMonth(),
        ]);
    }

    private function payment(Subscription $subscription, float $amount): Payment
    {
        return Payment::create([
            'subscription_id' => $subscription->id,
            'gateway' => 'razorpay',
            'gateway_ref' => 'pay_'.uniqid(),
            'amount' => $amount,
            'status' => 'captured',
            'paid_at' => now(),
        ]);
    }

    private function lead(Client $client, User $creator): Lead
    {
        return Lead::create([
            'client_id' => $client->id,
            'name' => 'Test Lead '.uniqid(),
            'phone' => '9'.fake()->unique()->numerify('#########'),
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'created_by' => $creator->id,
        ]);
    }
}
