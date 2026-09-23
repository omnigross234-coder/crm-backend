<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\AuthEvent;
use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Security Workstream D: Super Admin session/token visibility and
 * revocation over App\Http\Controllers\Api\SuperAdminSessionController.
 *
 * Real HTTP + real database verification against the running app
 * (documented in the workstream report) was performed before these tests
 * were written, using disposable accounts deleted immediately afterward.
 */
class SuperAdminSessionManagementTest extends TestCase
{
    use RefreshDatabase;

    private function superAdmin(string $email = 'wsd-super@example.com'): User
    {
        return User::create([
            'client_id' => null,
            'name' => 'Super Admin',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function tenantUser(string $email = 'wsd-tenant-user@example.com', ?int $clientId = null): User
    {
        return User::create([
            'client_id' => $clientId ?? Client::factory()->create()->id,
            'name' => 'Tenant User',
            'email' => $email,
            'password' => Hash::make('password'),
            'role' => 'sales',
            'status' => 'active',
        ]);
    }

    /**
     * Deliberately real Bearer-token headers rather than actingAs(): a
     * test that also makes a genuinely independent second request (e.g.
     * confirming a revoked token stops authenticating, or that login
     * still works afterward) needs the guard to actually verify a real
     * token each time — actingAs() persistently overrides guard
     * resolution for the rest of the test, which both defeats those
     * assertions and, for a later Auth::attempt() call, corrupts the
     * default guard resolution entirely (confirmed: it throws
     * BadMethodCallException on RequestGuard::attempt).
     */
    private function bearerHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('test-actor-token')->plainTextToken];
    }

    // ── AUTHORIZATION ───────────────────────────────────────────────

    public function test_super_admin_can_list_a_users_sessions(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $target->createToken('crm-token');

        $this->withHeaders($this->bearerHeader($admin))
            ->getJson("/api/admin/users/{$target->id}/sessions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_unauthenticated_request_is_denied(): void
    {
        $target = $this->tenantUser();

        $this->getJson("/api/admin/users/{$target->id}/sessions")->assertStatus(401);
    }

    public function test_non_super_admin_is_denied(): void
    {
        $target = $this->tenantUser();
        $nonAdmin = $this->tenantUser('wsd-nonadmin@example.com');

        $this->withHeaders($this->bearerHeader($nonAdmin))
            ->getJson("/api/admin/users/{$target->id}/sessions")
            ->assertStatus(403);
    }

    public function test_super_admin_can_manage_sessions_for_a_user_in_any_tenant(): void
    {
        // Super Admin's legitimate cross-tenant reach — not a bug, and
        // deliberately not restricted the way an ordinary tenant-scoped
        // API would be.
        $admin = $this->superAdmin();
        $clientA = Client::factory()->create();
        $target = $this->tenantUser('wsd-cross-tenant@example.com', $clientA->id);
        $target->createToken('crm-token');

        $this->withHeaders($this->bearerHeader($admin))
            ->getJson("/api/admin/users/{$target->id}/sessions")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_ordinary_authenticated_access_is_unaffected_by_these_new_routes(): void
    {
        $user = $this->tenantUser();
        $token = $user->createToken('crm-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertOk();
    }

    // ── LISTING ─────────────────────────────────────────────────────

    public function test_expired_sessions_are_listed_with_expired_status(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $target->createToken('crm-token', ['*'], now()->subDay());

        $response = $this->withHeaders($this->bearerHeader($admin))
            ->getJson("/api/admin/users/{$target->id}/sessions");

        $response->assertOk();
        $this->assertSame('expired', $response->json('data.0.status'));
    }

    public function test_listing_never_exposes_a_raw_token_or_hash(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $newToken = $target->createToken('crm-token');
        $rawToken = $newToken->plainTextToken;
        $hashedToken = $newToken->accessToken->token;

        $response = $this->withHeaders($this->bearerHeader($admin))
            ->getJson("/api/admin/users/{$target->id}/sessions");

        $response->assertOk();
        $body = $response->getContent();
        $this->assertStringNotContainsString($rawToken, $body);
        $this->assertStringNotContainsString($hashedToken, $body);
        $this->assertArrayNotHasKey('token', $response->json('data.0'));
        $this->assertArrayNotHasKey('hash', $response->json('data.0'));

        // Schema-level guarantee on the response shape itself.
        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'created_at', 'last_used_at', 'expires_at', 'status', 'is_current'],
            array_keys($response->json('data.0'))
        );
    }

    // ── REVOCATION ──────────────────────────────────────────────────

    public function test_one_session_can_be_revoked(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $token = $target->createToken('crm-token')->accessToken;

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertOk();

        $this->assertSame(0, PersonalAccessToken::where('id', $token->id)->count());
    }

    public function test_revoked_token_can_no_longer_authenticate(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $issued = $target->createToken('crm-token');

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$issued->accessToken->id}")
            ->assertOk();

        // Required whenever a later request in the same test authenticates
        // as a different token — see UserPasswordChangeRevokesTokensTest's
        // own class doc comment for the established explanation (Sanctum's
        // guard otherwise caches the first request's resolved user across
        // "requests" that share one in-process container).
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', "Bearer {$issued->plainTextToken}")
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_revoking_an_already_revoked_session_is_handled_safely(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $token = $target->createToken('crm-token')->accessToken;

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertOk();

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertStatus(404);
    }

    public function test_revoking_a_nonexistent_session_is_handled_safely(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/999999999")
            ->assertStatus(404);
    }

    public function test_a_session_belonging_to_a_different_user_cannot_be_revoked_through_this_route(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser('wsd-target-a@example.com');
        $someoneElse = $this->tenantUser('wsd-target-b@example.com');
        $othersToken = $someoneElse->createToken('crm-token')->accessToken;

        // Scoped to $target's own tokens — someone else's real token id
        // must 404 here, not succeed against the wrong user.
        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$othersToken->id}")
            ->assertStatus(404);

        $this->assertSame(1, PersonalAccessToken::where('id', $othersToken->id)->count());
    }

    public function test_expired_session_can_still_be_revoked_as_cleanup(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $token = $target->createToken('crm-token', ['*'], now()->subDay())->accessToken;

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertOk();
    }

    public function test_super_admin_cannot_revoke_their_own_current_session(): void
    {
        $admin = $this->superAdmin();
        $issued = $admin->createToken('crm-token');

        $response = $this->withHeader('Authorization', "Bearer {$issued->plainTextToken}")
            ->deleteJson("/api/admin/users/{$admin->id}/sessions/{$issued->accessToken->id}");

        $response->assertStatus(403);
        $this->assertSame(1, PersonalAccessToken::where('id', $issued->accessToken->id)->count());
    }

    public function test_super_admin_can_revoke_their_own_non_current_session(): void
    {
        $admin = $this->superAdmin();
        $current = $admin->createToken('crm-token');
        $otherSession = $admin->createToken('another-device');

        $response = $this->withHeader('Authorization', "Bearer {$current->plainTextToken}")
            ->deleteJson("/api/admin/users/{$admin->id}/sessions/{$otherSession->accessToken->id}");

        $response->assertOk();
        $this->assertSame(0, PersonalAccessToken::where('id', $otherSession->accessToken->id)->count());
    }

    public function test_revoking_another_super_admins_session_is_allowed_with_no_extra_gating(): void
    {
        // Determination documented in the workstream report: a revoked
        // token is always recoverable via a normal login (the account and
        // password are untouched), so this is a legitimate security
        // operation (e.g. incident response) with no extra approval step.
        $actor = $this->superAdmin('wsd-actor-admin@example.com');
        $otherAdmin = $this->superAdmin('wsd-other-admin@example.com');
        $token = $otherAdmin->createToken('crm-token')->accessToken;

        $this->withHeaders($this->bearerHeader($actor))
            ->deleteJson("/api/admin/users/{$otherAdmin->id}/sessions/{$token->id}")
            ->assertOk();
    }

    public function test_revoke_all_sessions_for_a_user(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $target->createToken('a');
        $target->createToken('b');

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions")
            ->assertOk();

        $this->assertSame(0, $target->tokens()->count());
    }

    public function test_revoke_all_cannot_be_used_on_ones_own_account(): void
    {
        // The self-protection guard that keeps this action from ever
        // creating an "I just logged myself out mid-operation" state —
        // see the controller's doc comment for why this is a full block
        // rather than an "all except my current session" partial one.
        $admin = $this->superAdmin();
        $issued = $admin->createToken('crm-token');
        $admin->createToken('another-device');

        $response = $this->withHeader('Authorization', "Bearer {$issued->plainTextToken}")
            ->deleteJson("/api/admin/users/{$admin->id}/sessions");

        $response->assertStatus(403);
        $this->assertSame(2, $admin->tokens()->count());
    }

    public function test_revoke_all_on_another_super_admin_does_not_create_an_unusable_state(): void
    {
        // The other admin's account and password are untouched — they can
        // still log in and receive a brand new token immediately, so
        // revoking every one of their existing sessions never leaves the
        // platform without usable Super Admin access.
        $actor = $this->superAdmin('wsd-actor-admin-2@example.com');
        $otherAdmin = $this->superAdmin('wsd-other-admin-2@example.com', );
        $otherAdmin->createToken('a');
        $otherAdmin->createToken('b');

        $this->withHeaders($this->bearerHeader($actor))
            ->deleteJson("/api/admin/users/{$otherAdmin->id}/sessions")
            ->assertOk();

        $this->assertSame(0, $otherAdmin->tokens()->count());

        // The claim under test is that the ACCOUNT remains fully usable —
        // proven directly against its persisted state, rather than by
        // chaining a real /api/auth/login call after an already-Bearer-
        // authenticated request in the same test method (confirmed via a
        // separate run to hit a Sanctum/Auth-guard test-only quirk: the
        // default guard resolution does not cleanly reset for a
        // subsequent Auth::attempt()-based call in-process, unrelated to
        // application behavior — the equivalent real end-to-end flow was
        // already live-verified against the running app, see the
        // workstream report's Phase 9).
        $otherAdmin->refresh();
        $this->assertSame('active', $otherAdmin->status);
        $this->assertSame('super_admin', $otherAdmin->role);
        $this->assertTrue(Hash::check('password', $otherAdmin->password));
    }

    // ── AUDITING ────────────────────────────────────────────────────

    public function test_revocation_creates_an_activity_log_record(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $token = $target->createToken('crm-token')->accessToken;

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertOk();

        $log = ActivityLog::where('action', 'session.revoked')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame($target->id, $log->subject_id);
        $this->assertSame($target->client_id, $log->meta['target_client_id']);
    }

    public function test_revoke_all_creates_an_activity_log_record(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $target->createToken('a');
        $target->createToken('b');

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions")
            ->assertOk();

        $log = ActivityLog::where('action', 'session.revoked_all')->firstOrFail();
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame(2, $log->meta['sessions_revoked']);
    }

    public function test_no_secrets_appear_in_audit_metadata(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $issued = $target->createToken('crm-token');

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$issued->accessToken->id}")
            ->assertOk();

        $log = ActivityLog::where('action', 'session.revoked')->firstOrFail();
        $serialized = json_encode($log->toArray());
        $this->assertStringNotContainsString($issued->plainTextToken, $serialized);
        $this->assertStringNotContainsString($issued->accessToken->token, $serialized);
    }

    public function test_session_revocation_does_not_fabricate_an_auth_event(): void
    {
        $admin = $this->superAdmin();
        $target = $this->tenantUser();
        $token = $target->createToken('crm-token')->accessToken;

        $this->withHeaders($this->bearerHeader($admin))
            ->deleteJson("/api/admin/users/{$target->id}/sessions/{$token->id}")
            ->assertOk();

        // Phase 6 decision: an admin-triggered revocation is not a
        // self-service "logout" and is not recorded in auth_events at
        // all — it belongs solely in activity_logs, matching Workstream
        // C's own established rule for every non-self-service token
        // revocation call site in this app.
        $this->assertSame(0, AuthEvent::count());
    }

    // ── REGRESSION ──────────────────────────────────────────────────

    public function test_existing_login_still_works(): void
    {
        $user = $this->tenantUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();
    }

    public function test_existing_logout_still_works(): void
    {
        $user = $this->tenantUser();
        $token = $user->createToken('crm-token')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();
    }

    public function test_existing_logout_all_still_works(): void
    {
        $user = $this->tenantUser();
        $token = $user->createToken('crm-token')->plainTextToken;
        $user->createToken('another-device');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout-all')
            ->assertOk();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_rate_limiting_from_workstream_a_remains_intact(): void
    {
        config(['rate_limits.login.max_attempts' => 2, 'rate_limits.login.decay_minutes' => 1]);
        $email = 'wsd-ratelimit-check@example.com';

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'wrong'])->assertStatus(429);
    }

    public function test_workstream_c_login_success_auth_event_still_fires_normally(): void
    {
        $user = $this->tenantUser();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->assertSame(1, AuthEvent::where('event', 'login_success')->where('user_id', $user->id)->count());
    }
}
