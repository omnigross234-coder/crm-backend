<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks API access once a client's subscription is genuinely no longer
 * usable. Checks, in order: client exists -> client active -> subscription
 * usable -> continue.
 *
 * Deliberately self-contained (re-checks client existence/active state
 * rather than assuming EnsureAccountIsActive already ran) so it's safe to
 * use on its own, even though pairing both is still recommended.
 *
 * IMPORTANT — legacy clients: a client whose subscription_status is blank
 * (never onboarded onto the billing system) is allowed through. This
 * middleware only enforces once a client actually has billing state to
 * check. Deciding whether/when to migrate existing clients onto real
 * subscription rows is a separate decision, not made here.
 *
 * Also checks dates directly (trial_end_date / subscription_end_date), not
 * just the status column — no cron exists yet to flip status the moment a
 * period ends, so this is the safety net that keeps access control correct
 * even before that automation exists.
 */
class EnsureSubscriptionIsActive
{
    /** Statuses that always block, regardless of any date field. */
    private const ALWAYS_BLOCKED = ['trial_expired', 'expired', 'suspended'];

    public function handle(Request $request, Closure $next): Response
    {
        // Master kill switch. Defaults OFF (no-op) so this middleware can be
        // safely wrapped around every route today, with zero behavior
        // change, and only starts actually enforcing once you explicitly
        // set SUBSCRIPTION_ENFORCEMENT=true in .env. Flip it back to false
        // to instantly disable enforcement everywhere without touching any
        // route file — that's the rollback plan.
        //
        // Reads env() directly rather than through config() because this
        // app has no SSH access to run `php artisan config:cache` /
        // `config:clear` — a cached config would make .env edits invisible.
        // If you ever DO start caching config on this app, move this into
        // a proper config/subscription.php file instead.
        if (! filter_var(env('SUBSCRIPTION_ENFORCEMENT', false), FILTER_VALIDATE_BOOLEAN)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $this->deny('Unauthenticated.', 401);
        }

        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $client = $user->client;

        if (! $client) {
            return $this->deny('No client account associated with this user.', 403);
        }

        if ($client->status !== 'active') {
            return $this->deny('Your client account is suspended.', 403);
        }

        if (! $this->subscriptionIsUsable($client)) {
            return $this->deny($this->messageFor($client->subscription_status), 403);
        }

        return $next($request);
    }

    private function subscriptionIsUsable($client): bool
    {
        $status = $client->subscription_status;

        // Never onboarded onto billing at all — don't enforce.
        if (blank($status)) {
            return true;
        }

        if (in_array($status, self::ALWAYS_BLOCKED, true)) {
            return false;
        }

        return match ($status) {
            'trial' => ! $this->isPast($client->trial_end_date),
            'active', 'cancelled' => ! $this->isPast($client->subscription_end_date),
            default => $this->failOpenOnUnknownStatus($status, $client->id),
        };
    }

    private function isPast($date): bool
    {
        return $date !== null && $date->isPast();
    }

    private function failOpenOnUnknownStatus(string $status, int $clientId): bool
    {
        Log::warning('EnsureSubscriptionIsActive: unrecognized subscription_status, failing open.', [
            'client_id' => $clientId,
            'subscription_status' => $status,
        ]);

        return true;
    }

    private function messageFor(?string $status): string
    {
        return match ($status) {
            'trial', 'trial_expired' => 'Your free trial has ended. Please subscribe to continue.',
            'active', 'cancelled', 'expired' => 'Your subscription period has ended. Please renew to continue.',
            'suspended' => 'Your account has been suspended. Please contact support.',
            default => 'Your subscription is not active.',
        };
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json(['success' => false, 'message' => $message], $status);
    }
}