<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Security Workstream D: Super Admin visibility and revocation over a
 * specific user's active Sanctum tokens — treated as "sessions" only in
 * the sense the actual architecture supports (one `personal_access_tokens`
 * row per issued token; see the workstream report's Phase 2 for the full
 * reasoning on what "session" means here and what it deliberately does
 * NOT mean, e.g. no device/location/browser data this table doesn't
 * actually contain).
 *
 * No new token storage, no new columns, no raw token/hash ever leaves
 * these endpoints — every response exposes only id/name/timestamps/status,
 * matching Phase 2's explicit "do not expose" list.
 *
 * SELF-PROTECTION (Phase 3): revocation is deliberately NOT equivalent to
 * SuperAdminUserController's account-level deactivation/demotion guards —
 * a revoked token is trivially replaced by logging in again (the account
 * and password are untouched), whereas deactivating/demoting the last
 * active Super Admin ACCOUNT genuinely blocks every future login. This
 * workstream therefore does not duplicate SuperAdminUserController's
 * `guardLastActiveSuperAdmin` "last active Super Admin" concept — it does
 * not map to a real lockout risk for tokens, and inventing one would be
 * exactly the unjustified complexity the brief warns against. What IS
 * implemented: an actor can never revoke their own CURRENT session
 * (single-revoke) and can never revoke ALL of their own sessions through
 * this admin surface at all (revoke-all) — both are disruptive
 * self-inflicted actions worth blocking outright, mirroring
 * SuperAdminUserController::guardSelfProtection()'s equally blunt "you
 * cannot deactivate your own account". Revoking ANOTHER Super Admin's
 * session (including all of it) is deliberately ALLOWED with no extra
 * gating: it is a legitimate security operation (e.g. incident response
 * on a compromised admin account) and the target can always log back in
 * — see the workstream report for the full "does this need additional
 * protection?" determination.
 */
class SuperAdminSessionController extends Controller
{
    /**
     * List a specific user's tokens. Deliberately per-user, not a global
     * session firehose — Super Admin session review is "investigate this
     * account", matching the brief's "smallest maintainable solution".
     */
    public function index(Request $request, User $user): JsonResponse
    {
        $currentTokenId = $this->currentTokenId($request);

        $sessions = $user->tokens()->orderByDesc('created_at')->get()->map(
            fn (PersonalAccessToken $token) => $this->present($token, $request->user(), $user, $currentTokenId)
        );

        return response()->json(['success' => true, 'data' => $sessions]);
    }

    /**
     * Revoke exactly one session. Revocation IS deletion — Sanctum has no
     * separate "revoked" flag, so this is the same mechanism
     * AuthController::logout() already uses on itself; here it is applied,
     * with authorization, to another user's token.
     */
    public function destroy(Request $request, User $user, int $session): JsonResponse
    {
        $actor = $request->user();
        $currentTokenId = $this->currentTokenId($request);

        if ($actor->id === $user->id && $session === $currentTokenId) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot revoke your own currently active session.',
                'data' => null,
            ], 403);
        }

        // Scoped to $user->tokens() so a session belonging to a different
        // user can never be found here — an ID that exists but belongs to
        // someone else is indistinguishable from one that doesn't exist
        // at all, deliberately (no cross-user existence leak).
        $token = $user->tokens()->find($session);

        if (! $token) {
            return response()->json([
                'success' => false,
                'message' => 'Session not found.',
                'data' => null,
            ], 404);
        }

        $tokenId = $token->id;
        $tokenName = $token->name;
        $token->delete();

        ActivityLogger::log('session.revoked', $user, [
            'session_id' => $tokenId,
            'token_name' => $tokenName,
            'target_user_id' => $user->id,
            'target_client_id' => $user->client_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Session revoked.',
            'data' => null,
        ]);
    }

    /**
     * Revoke every session belonging to $user. Blocked entirely when the
     * target is the acting Super Admin themselves — see the class doc
     * comment for why this is a full block rather than an "all except my
     * current one" partial operation.
     */
    public function destroyAll(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if ($actor->id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot revoke all of your own sessions through this action. Use logout-all, or revoke individual other sessions one at a time instead.',
                'data' => null,
            ], 403);
        }

        $count = $user->tokens()->count();
        $user->tokens()->delete();

        ActivityLogger::log('session.revoked_all', $user, [
            'sessions_revoked' => $count,
            'target_user_id' => $user->id,
            'target_client_id' => $user->client_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => "All sessions revoked ({$count}).",
            'data' => null,
        ]);
    }

    private function currentTokenId(Request $request): ?int
    {
        $token = $request->user()->currentAccessToken();

        return $token instanceof PersonalAccessToken ? $token->id : null;
    }

    private function present(PersonalAccessToken $token, User $actor, User $owner, ?int $currentTokenId): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'created_at' => $token->created_at,
            'last_used_at' => $token->last_used_at,
            'expires_at' => $token->expires_at,
            'status' => ($token->expires_at && $token->expires_at->isPast()) ? 'expired' : 'active',
            'is_current' => $actor->id === $owner->id && $token->id === $currentTokenId,
        ];
    }
}
