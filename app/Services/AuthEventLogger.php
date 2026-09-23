<?php

namespace App\Services;

use App\Models\AuthEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Security Workstream C: a narrow, append-only authentication history —
 * login/logout/password-reset events for the future Super Admin Security
 * Center. Deliberately separate from ActivityLogger/`activity_logs`, which
 * remains the source of truth for administrative/business actions:
 *
 *  - ActivityLogger::log() always attributes to Auth::user() (the CURRENT
 *    authenticated actor) — it has no way to represent "an attempted
 *    login that never authenticated" or "a password-reset request for an
 *    email that doesn't even have an account", both of which are exactly
 *    the events this workstream needs to capture.
 *  - The one shared code path that legitimately serves both systems
 *    (PasswordResetController::issueResetToken(), called by both the
 *    public self-service forgot-password endpoint AND
 *    SuperAdminUserController::sendPasswordReset()) is deliberately NOT
 *    where auth-event logging happens — the admin-triggered path already
 *    has its own ActivityLogger::log('user.password_reset_initiated', ...)
 *    call; adding auth_events logging inside the shared method would
 *    double-record that same admin action. Instead, `password_reset_requested`
 *    is logged directly in PasswordResetController::forgotPassword() —
 *    the genuinely self-service, unauthenticated flow only.
 *
 * Event taxonomy (small and closed by design — see the workstream report
 * for why each candidate event was included or rejected):
 *   - login_success
 *   - login_failed            (failure_reason: invalid_credentials,
 *                               account_inactive, client_suspended)
 *   - logout                  (only the two explicit self-service
 *                               endpoints — AuthController::logout() and
 *                               ::logoutAllDevices() — every other
 *                               tokens()->delete() call site in this app
 *                               is an admin/system-triggered revocation,
 *                               not a user-initiated logout, and is never
 *                               recorded as one)
 *   - password_reset_requested
 *   - password_reset_succeeded
 *   - password_reset_failed   (failure_reason: no_active_reset_request,
 *                               token_expired, invalid_token, user_not_found)
 *
 * Privacy: never pass a password, reset token, access token, FCM token,
 * Authorization header, or raw request body into $attributes. Only
 * user_id/client_id (when actually known — NULL otherwise, never
 * inferred), a normalized login_identifier, ip/user-agent, and a short
 * fixed failure_reason category are ever stored.
 *
 * Failure isolation: persistence failures are caught here and logged
 * through the app's existing Log facade — they must never turn a
 * successful authentication into a failure, and never surface a
 * database/logging error to the client.
 */
class AuthEventLogger
{
    public static function log(string $event, string $result, array $attributes = []): void
    {
        try {
            AuthEvent::create(array_merge([
                'user_id' => null,
                'client_id' => null,
                'login_identifier' => null,
                'ip_address' => request()?->ip(),
                'user_agent' => self::safeUserAgent(),
                'failure_reason' => null,
            ], $attributes, [
                'event' => $event,
                'result' => $result,
            ]));
        } catch (Throwable $e) {
            Log::error('Failed to persist auth event.', [
                'event' => $event,
                'result' => $result,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function safeUserAgent(): ?string
    {
        $userAgent = request()?->userAgent();

        return $userAgent ? mb_substr($userAgent, 0, 255) : null;
    }
}
