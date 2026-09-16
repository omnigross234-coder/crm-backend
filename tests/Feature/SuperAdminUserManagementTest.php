<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Phase 6 (Super Admin User Management Control Center).
 *
 * Covers: GET/POST/PUT /api/admin/users, PATCH /api/admin/users/{id}/status,
 * POST /api/admin/users/{id}/send-password-reset.
 *
 * Root architecture verified during the Phase 6 audit before writing any
 * of this: there was previously ZERO Super Admin access to user
 * management of any kind (the tenant-scoped UserController routes are
 * gated by role:admin,client_admin, which explicitly excludes
 * super_admin). This is new functionality, not a defect fix.
 */
class SuperAdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    // ── Authorization ────────────────────────────────────────────────────

    public function test_super_admin_can_list_users_across_all_tenants(): void
    {
        $superAdmin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $this->tenantUser('sales', $clientA->id);
        $this->tenantUser('sales', $clientB->id);

        $response = $this->actingAs($superAdmin)->getJson('/api/admin/users')->assertOk();

        $this->assertGreaterThanOrEqual(3, $response->json('meta.total'));
    }

    public function test_super_admin_can_view_a_user_in_any_tenant(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $this->actingAs($superAdmin)
            ->getJson("/api/admin/users/{$target->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $target->id);
    }

    public function test_super_admin_can_create_a_user_for_any_tenant(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $response = $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'New Sales Rep',
            'email' => 'new-rep@example.com',
            'role' => 'sales',
            'client_id' => $client->id,
        ])->assertCreated();

        $this->assertDatabaseHas('users', [
            'email' => 'new-rep@example.com',
            'role' => 'sales',
            'client_id' => $client->id,
            'status' => 'active',
        ]);
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_client_admin_cannot_access_super_admin_user_management(): void
    {
        $client = Client::factory()->create();
        $clientAdmin = $this->tenantUser('client_admin', $client->id);

        $this->actingAs($clientAdmin)->getJson('/api/admin/users')->assertForbidden();
        $this->actingAs($clientAdmin)->postJson('/api/admin/users', ['name' => 'x'])->assertForbidden();
    }

    public function test_admin_role_cannot_access_super_admin_user_management(): void
    {
        $client = Client::factory()->create();
        $admin = $this->tenantUser('admin', $client->id);

        $this->actingAs($admin)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_sales_cannot_access_super_admin_user_management(): void
    {
        $client = Client::factory()->create();
        $sales = $this->tenantUser('sales', $client->id);

        $this->actingAs($sales)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_sales_employee_cannot_access_super_admin_user_management(): void
    {
        $client = Client::factory()->create();
        $salesEmployee = $this->tenantUser('sales_employee', $client->id);

        $this->actingAs($salesEmployee)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_sales_manager_cannot_access_super_admin_user_management(): void
    {
        $client = Client::factory()->create();
        $salesManager = $this->tenantUser('sales_manager', $client->id);

        $this->actingAs($salesManager)->getJson('/api/admin/users')->assertForbidden();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/admin/users')->assertUnauthorized();
        $this->postJson('/api/admin/users', [])->assertUnauthorized();
    }

    // ── Role assignment (verified vs. excluded) ─────────────────────────

    public function test_sales_employee_is_an_assignable_role(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Employee', 'email' => 'employee@example.com',
            'role' => 'sales_employee', 'client_id' => $client->id,
        ])->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'employee@example.com', 'role' => 'sales_employee']);
    }

    /**
     * sales_manager is deliberately NOT assignable — LeadController::assign()
     * (POST /leads/{id}/assign) hard-codes an allow-list that omits
     * sales_manager, so a sales_manager created today could not actually be
     * assigned leads through the app's normal workflow. Verified during
     * the Phase 6 audit; not fixed here (out of scope — would be altering
     * unrelated permission semantics merely to enable a role).
     */
    public function test_sales_manager_is_not_an_assignable_role(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Manager', 'email' => 'manager@example.com',
            'role' => 'sales_manager', 'client_id' => $client->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'manager@example.com']);
    }

    public function test_client_admin_is_an_assignable_role(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'New Admin', 'email' => 'new-admin@example.com',
            'role' => 'client_admin', 'client_id' => $client->id,
        ])->assertCreated();
    }

    public function test_super_admin_role_can_be_created_and_never_has_a_tenant(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();

        $response = $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Second Super Admin', 'email' => 'second-sa@example.com',
            'role' => 'super_admin',
        ])->assertCreated();

        $this->assertNull($response->json('data.client_id'));
    }

    public function test_super_admin_role_with_a_client_id_is_rejected(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Bad', 'email' => 'bad@example.com',
            'role' => 'super_admin', 'client_id' => $client->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'bad@example.com']);
    }

    public function test_tenant_scoped_role_without_a_client_id_is_rejected(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Bad', 'email' => 'bad2@example.com', 'role' => 'sales',
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'bad2@example.com']);
    }

    public function test_role_change_is_audited(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}", ['role' => 'admin'])
            ->assertOk()
            ->assertJsonPath('data.role', 'admin');

        $log = ActivityLog::where('action', 'user.updated')->where('subject_id', $target->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('sales', $log->meta['before']['role']);
        $this->assertSame('admin', $log->meta['after']['role']);
    }

    // ── Tenant assignment ────────────────────────────────────────────────

    public function test_super_admin_can_move_a_user_to_a_different_tenant(): void
    {
        $superAdmin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $target = $this->tenantUser('sales', $clientA->id);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}", ['client_id' => $clientB->id])
            ->assertOk()
            ->assertJsonPath('data.client_id', $clientB->id);

        $log = ActivityLog::where('action', 'user.updated')->where('subject_id', $target->id)->latest('id')->first();
        $this->assertSame($clientA->id, $log->meta['before']['client_id']);
        $this->assertSame($clientB->id, $log->meta['after']['client_id']);
    }

    public function test_moving_an_active_user_into_a_full_tenant_is_blocked(): void
    {
        $superAdmin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $fullClient = Client::factory()->create(['seat_limit' => 1]);
        $this->tenantUser('sales', $fullClient->id); // occupies the only seat
        $target = $this->tenantUser('sales', $clientA->id);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}", ['client_id' => $fullClient->id])
            ->assertStatus(422);

        $this->assertSame($clientA->id, $target->fresh()->client_id);
    }

    public function test_nonexistent_target_tenant_is_rejected(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$target->id}", ['client_id' => 999999])
            ->assertStatus(422);

        $this->assertSame($client->id, $target->fresh()->client_id);
    }

    public function test_non_super_admin_cannot_use_client_id_parameter_to_reassign_tenants(): void
    {
        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();
        $clientAdmin = $this->tenantUser('client_admin', $client->id);
        $target = $this->tenantUser('sales', $client->id);

        // Existing tenant-scoped endpoint doesn't even accept client_id —
        // confirms non-Super-Admin cannot escape tenant isolation this way.
        $this->actingAs($clientAdmin)
            ->putJson("/api/users/{$target->id}", ['name' => $target->name, 'email' => $target->email])
            ->assertOk();

        $this->assertSame($client->id, $target->fresh()->client_id);

        // And the Super-Admin-only route itself rejects this role outright.
        $this->actingAs($clientAdmin)
            ->putJson("/api/admin/users/{$target->id}", ['client_id' => $otherClient->id])
            ->assertForbidden();
    }

    // ── Self-protection ──────────────────────────────────────────────────

    public function test_super_admin_cannot_demote_their_own_account(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$superAdmin->id}", ['role' => 'admin'])
            ->assertForbidden();

        $this->assertSame('super_admin', $superAdmin->fresh()->role);
    }

    public function test_super_admin_cannot_deactivate_their_own_account(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->patchJson("/api/admin/users/{$superAdmin->id}/status")
            ->assertForbidden();

        $this->assertSame('active', $superAdmin->fresh()->status);
    }

    public function test_super_admin_cannot_reassign_their_own_tenant(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)
            ->putJson("/api/admin/users/{$superAdmin->id}", ['client_id' => $client->id])
            ->assertForbidden();

        $this->assertNull($superAdmin->fresh()->client_id);
    }

    public function test_demoting_a_super_admin_succeeds_when_others_remain_active(): void
    {
        $actingSuperAdmin = $this->superAdmin();
        $anotherSuperAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($actingSuperAdmin)
            ->putJson("/api/admin/users/{$anotherSuperAdmin->id}", ['role' => 'sales', 'client_id' => $client->id])
            ->assertOk();

        $this->assertSame('sales', $anotherSuperAdmin->fresh()->role);
    }

    /**
     * IMPORTANT ARCHITECTURAL FINDING (verified while writing this test —
     * see the Phase 6 report's "Self-Protection" section for the full
     * writeup): the count-based "last active super_admin" branch in
     * guardLastActiveSuperAdmin() can only ever be reached via a SELF
     * action through this API. Every authenticated request to this
     * controller must pass EnsureSuperAdmin (role check) AND
     * EnsureAccountIsActive (the ACTOR's own status must be 'active') —
     * so whoever is making the request is, by definition, themselves a
     * currently-active super_admin. That means if the target is a
     * DIFFERENT user, the count of active super_admins is always >= 2
     * at the moment of the check (the actor + the target), so removing
     * the target's super_admin status can never bring the count to zero.
     * The only way to actually reach "count would become zero" is a
     * self-action — already blocked, unconditionally and first, by the
     * separate, simpler guardSelfProtection() rule (proven by the tests
     * above: test_super_admin_cannot_demote_their_own_account,
     * test_super_admin_cannot_deactivate_their_own_account).
     *
     * A first version of this test tried to exercise the count-based
     * branch via a non-self actor and produced a false positive: the
     * "attacking" actor had already been demoted to a non-super_admin
     * role earlier in the test, so the 403 it received actually came
     * from the EnsureSuperAdmin route middleware rejecting the actor
     * outright — never reaching guardLastActiveSuperAdmin() at all. That
     * flawed test is replaced with this one, which verifies the guard
     * method directly (bypassing the middleware stack, exactly what
     * every real caller of this method through the HTTP API cannot do),
     * proving the guard itself is correct even though it is unreachable
     * as an independent path through the live API today. It remains in
     * the controller as defense-in-depth against future architectural
     * changes (e.g. a bulk-action endpoint, or an actor-verification
     * path that doesn't require the actor to be active).
     */
    public function test_last_active_super_admin_guard_logic_is_correct_even_though_self_protection_is_what_enforces_it_live(): void
    {
        $onlyActiveSuperAdmin = $this->superAdmin();
        User::where('role', 'super_admin')->where('id', '!=', $onlyActiveSuperAdmin->id)->update(['status' => 'inactive']);
        $this->assertSame(1, User::where('role', 'super_admin')->where('status', 'active')->count());

        $controller = new \App\Http\Controllers\Api\SuperAdminUserController();
        $method = new \ReflectionMethod($controller, 'guardLastActiveSuperAdmin');
        $method->setAccessible(true);

        $blockedForDemotion = $method->invoke($controller, $onlyActiveSuperAdmin, true, false);
        $this->assertNotNull($blockedForDemotion);
        $this->assertSame(422, $blockedForDemotion->getStatusCode());

        $blockedForDeactivation = $method->invoke($controller, $onlyActiveSuperAdmin, false, true);
        $this->assertNotNull($blockedForDeactivation);
        $this->assertSame(422, $blockedForDeactivation->getStatusCode());

        // Control: with a second active super_admin present, neither
        // change type is blocked by this guard.
        $this->superAdmin();
        $this->assertSame(2, User::where('role', 'super_admin')->where('status', 'active')->count());
        $allowed = $method->invoke($controller, $onlyActiveSuperAdmin, true, false);
        $this->assertNull($allowed);
    }

    // ── ID / value tampering ─────────────────────────────────────────────

    public function test_nonexistent_user_id_returns_404_not_a_500(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)->getJson('/api/admin/users/999999999')->assertNotFound();
        $this->actingAs($superAdmin)->putJson('/api/admin/users/999999999', ['name' => 'x'])->assertNotFound();
    }

    public function test_invalid_role_string_is_rejected(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Bad Role', 'email' => 'badrole@example.com',
            'role' => 'super_super_admin', 'client_id' => $client->id,
        ])->assertStatus(422);

        $this->assertDatabaseMissing('users', ['email' => 'badrole@example.com']);
    }

    public function test_invalid_status_string_is_rejected(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Bad Status', 'email' => 'badstatus@example.com',
            'role' => 'sales', 'client_id' => $client->id, 'status' => 'banned',
        ])->assertStatus(422);
    }

    // ── Sensitive data exposure ──────────────────────────────────────────

    public function test_user_list_and_detail_never_expose_password_hash(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $listResponse = $this->actingAs($superAdmin)->getJson('/api/admin/users')->assertOk();
        $this->assertArrayNotHasKey('password', $listResponse->json('data.0'));

        $detailResponse = $this->actingAs($superAdmin)->getJson("/api/admin/users/{$target->id}")->assertOk();
        $this->assertArrayNotHasKey('password', $detailResponse->json('data'));
    }

    public function test_password_reset_initiation_never_returns_a_token(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $response = $this->actingAs($superAdmin)
            ->postJson("/api/admin/users/{$target->id}/send-password-reset")
            ->assertOk();

        $body = $response->content();
        $this->assertStringNotContainsString('token', strtolower($body));
        $this->assertDatabaseHas('password_reset_tokens', ['email' => $target->email]);

        $log = ActivityLog::where('action', 'user.password_reset_initiated')->where('subject_id', $target->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('token', json_encode($log->meta));
    }

    public function test_password_reset_uses_the_existing_secure_mechanism(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $target = $this->tenantUser('sales', $client->id);

        $this->actingAs($superAdmin)
            ->postJson("/api/admin/users/{$target->id}/send-password-reset")
            ->assertOk();

        $row = DB::table('password_reset_tokens')->where('email', $target->email)->first();
        $this->assertNotNull($row);
        // The token stored is hashed, never the plaintext mailed to the user.
        $this->assertNotEmpty($row->token);
    }

    public function test_created_user_password_is_unguessable_and_unusable_until_reset(): void
    {
        Notification::fake();
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();

        $this->actingAs($superAdmin)->postJson('/api/admin/users', [
            'name' => 'Fresh User', 'email' => 'fresh@example.com',
            'role' => 'sales', 'client_id' => $client->id,
        ])->assertCreated();

        $created = User::where('email', 'fresh@example.com')->first();
        $this->assertFalse(Hash::check('', $created->password));
        $this->assertFalse(Hash::check('password', $created->password));
        $this->assertFalse(Hash::check('sales', $created->password));
        $this->assertFalse(Hash::check($created->email, $created->password));
    }


    // ── Filtering, search, pagination ────────────────────────────────────

    public function test_search_filters_by_name_or_email(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $this->tenantUser('sales', $client->id, 'Zebra Stripe', 'zebra-search@example.com');
        $this->tenantUser('sales', $client->id, 'Other Person', 'other-search@example.com');

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/users?search=Zebra')
            ->assertOk();

        $names = collect($response->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Zebra Stripe'));
        $this->assertFalse($names->contains('Other Person'));
    }

    public function test_role_filter_narrows_results(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        $this->tenantUser('sales', $client->id);
        $this->tenantUser('client_admin', $client->id);

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/users?role=client_admin')
            ->assertOk();

        $roles = collect($response->json('data'))->pluck('role')->unique();
        $this->assertSame(['client_admin'], $roles->values()->all());
    }

    public function test_tenant_filter_narrows_results(): void
    {
        $superAdmin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $this->tenantUser('sales', $clientA->id);
        $this->tenantUser('sales', $clientB->id);

        $response = $this->actingAs($superAdmin)
            ->getJson("/api/admin/users?client_id={$clientA->id}")
            ->assertOk();

        $clientIds = collect($response->json('data'))->pluck('client_id')->unique();
        $this->assertSame([$clientA->id], $clientIds->values()->all());
    }

    public function test_pagination_is_bounded_and_does_not_load_everything(): void
    {
        $superAdmin = $this->superAdmin();
        $client = Client::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            $this->tenantUser('sales', $client->id);
        }

        $response = $this->actingAs($superAdmin)
            ->getJson('/api/admin/users?per_page=2')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame(2, $response->json('meta.per_page'));
    }

    public function test_per_page_cannot_exceed_the_hard_maximum(): void
    {
        $superAdmin = $this->superAdmin();

        $this->actingAs($superAdmin)
            ->getJson('/api/admin/users?per_page=99999')
            ->assertStatus(422);
    }

    // ── Regression: existing client-admin user management unaffected ────

    public function test_existing_client_admin_user_management_still_works_unaffected(): void
    {
        $client = Client::factory()->create();
        $clientAdmin = $this->tenantUser('client_admin', $client->id);
        $target = $this->tenantUser('sales', $client->id);

        $this->actingAs($clientAdmin)
            ->getJson('/api/users')
            ->assertOk();

        $this->actingAs($clientAdmin)
            ->putJson("/api/users/{$target->id}", [
                'name' => 'Updated Via Old Route', 'email' => $target->email,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Via Old Route');
    }

    private function superAdmin(): User
    {
        return User::create([
            'client_id' => null,
            'name' => 'Super Admin '.uniqid(),
            'email' => 'superadmin-'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function tenantUser(string $role, int $clientId, string $name = 'Tenant User', ?string $email = null): User
    {
        return User::create([
            'client_id' => $clientId,
            'name' => $name,
            'email' => $email ?? uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
