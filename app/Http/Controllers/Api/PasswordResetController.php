<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class PasswordResetController extends Controller
{
    /**
     * Always returns the same generic message whether or not the email
     * exists — confirming/denying an account's existence is a real
     * enumeration risk on a public, unauthenticated endpoint like this.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        $genericResponse = response()->json([
            'success' => true,
            'message' => 'If an account exists for that email, a password reset link has been sent.',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user) {
            return $genericResponse;
        }

        static::issueResetToken($user);

        return $genericResponse;
    }

    /**
     * Phase 6 (Super Admin User Management): extracted from the body of
     * forgotPassword() above so a Super-Admin-initiated "Send password
     * reset" action (SuperAdminUserController::sendPasswordReset()) can
     * reuse the exact same secure mechanism — same token generation,
     * same hashing, same 60-minute-expiry row, same email — instead of
     * building a second reset system. forgotPassword() itself is
     * unchanged in behavior; this is a pure extraction.
     */
    public static function issueResetToken(User $user): void
    {
        $rawToken = Str::random(64);

        DB::table('password_reset_tokens')->where('email', $user->email)->delete();
        DB::table('password_reset_tokens')->insert([
            'email' => $user->email,
            'token' => Hash::make($rawToken),
            'created_at' => now(),
        ]);

        try {
            $user->notify(new ResetPasswordNotification($rawToken, $user->email));
        } catch (Throwable $e) {
            Log::warning('Password reset email failed to send.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            // Still return the generic success response either way — don't
            // leak whether sending failed.
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $invalid = response()->json([
            'success' => false,
            'message' => 'This reset link is invalid or has expired. Please request a new one.',
        ], 422);

        $record = DB::table('password_reset_tokens')->where('email', $data['email'])->first();

        if (! $record) {
            return $invalid;
        }

        // Same 60-minute expiry window Laravel's own default password
        // broker uses.
        //
        // Found while fixing SEC-F05 (Phase 2C Workstream G): diffInMinutes()
        // on this Carbon version returns a SIGNED value - now()->diffInMinutes()
        // of a past timestamp is NEGATIVE, so the un-flagged call here was
        // always comparing a negative number to 60 and could never be true.
        // Reset tokens were never actually expiring by this check at all
        // (confirmed live: a token 61 minutes old was still accepted before
        // this fix). absolute: true restores the intended "age in minutes,
        // regardless of direction" comparison.
        $expired = now()->diffInMinutes($record->created_at, absolute: true) > 60;

        // SEC-F05 (Phase 1 audit): this used to delete the token row on
        // ANY wrong guess, not just a genuine expiry — a single mistyped
        // token (by the legitimate user, or a guess by anyone else) killed
        // the real user's still-valid, unexpired reset link, forcing them
        // to request a new one. Only a true expiry should invalidate it;
        // a wrong guess should just fail this one attempt and leave the
        // real link usable for the rest of its window.
        if ($expired) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return $invalid;
        }

        if (! Hash::check($data['token'], $record->token)) {
            return $invalid;
        }

        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

            return $invalid;
        }

        $user->update(['password' => Hash::make($data['password'])]);

        // Revoke every existing session on this account — if someone else
        // had unauthorized access, resetting the password should log them
        // out everywhere, not just change the password while their
        // existing token keeps working.
        $user->tokens()->delete();

        DB::table('password_reset_tokens')->where('email', $data['email'])->delete();

        return response()->json([
            'success' => true,
            'message' => 'Your password has been reset. Please log in with your new password.',
        ]);
    }
}
