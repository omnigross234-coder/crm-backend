<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use App\Services\AuthEventLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        // Security Workstream C: normalized purely for auth_events
        // attribution (matches Workstream A's own email-normalization
        // pattern) — never used for the actual credential check itself,
        // which is untouched below.
        $loginIdentifier = Str::lower(trim((string) $request->input('email')));

        // Looked up separately from Auth::attempt()'s own internal check,
        // purely so a failed attempt against a REAL account can still be
        // attributed to that user_id/client_id in the audit trail (Phase 4:
        // "known user -> record user_id"). This does not change the HTTP
        // response in any way — it stays the identical generic message
        // regardless of whether $maybeUser is found — so the existing
        // account-enumeration-safe behavior from Workstream A is
        // unaffected; see AuthEventLoggingTest for the assertion that
        // proves this.
        $maybeUser = User::where('email', $loginIdentifier)->first();

        if (!Auth::attempt($request->only('email', 'password'))) {
            AuthEventLogger::log('login_failed', 'failure', [
                'user_id' => $maybeUser?->id,
                'client_id' => $maybeUser?->client_id,
                'login_identifier' => $loginIdentifier,
                'failure_reason' => 'invalid_credentials',
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data'    => null,
            ], 401);
        }

        /** @var User $user */
        $user  = Auth::user();

        if ($user->status === 'inactive') {
            AuthEventLogger::log('login_failed', 'failure', [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'login_identifier' => $loginIdentifier,
                'failure_reason' => 'account_inactive',
            ]);
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive.',
                'data'    => null,
            ], 403);
        }

        // A suspended tenant must prevent every linked user (client admin and
        // sales users) from receiving an API token. Super admins do not belong
        // to a client and remain unaffected.
        $user->load('client');
        if (! $user->isSuperAdmin() && $user->client?->status === 'suspended') {
            AuthEventLogger::log('login_failed', 'failure', [
                'user_id' => $user->id,
                'client_id' => $user->client_id,
                'login_identifier' => $loginIdentifier,
                'failure_reason' => 'client_suspended',
            ]);
            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Your client account is suspended. Please contact the platform administrator.',
                'data'    => null,
            ], 403);
        }

        $token = $user->createToken('crm-token')->plainTextToken;

        AuthEventLogger::log('login_success', 'success', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'login_identifier' => $loginIdentifier,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'user'  => $user,
                'token' => $token,
                'role'  => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Session/web-guard requests carry a Sanctum TransientToken, which
        // has no delete() method and no matching database row — only a real
        // Bearer-token request has something here to revoke.
        $token = $user->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        AuthEventLogger::log('logout', 'success', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data'    => null,
        ]);
    }

    /**
     * SEC-F06: self-service "sign out of all devices" — revokes every
     * Sanctum token belonging to the caller, not just the one used to make
     * this request. Same underlying pattern (`$user->tokens()->delete()`)
     * already used by ClientController/UserController::toggleStatus/
     * PasswordResetController for admin- or system-triggered revocation;
     * this is the first user-initiated route to it.
     */
    public function logoutAllDevices(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $user->tokens()->delete();

        AuthEventLogger::log('logout', 'success', [
            'user_id' => $user->id,
            'client_id' => $user->client_id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Logged out of all devices.',
            'data' => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Authenticated user.',
            'data'    => $request->user(),
        ]);
    }
}
