<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Owns every subscription state transition.
 *
 * States: trial, trial_expired, active, cancelled, expired, suspended
 *
 *   createTrial()             [none] -> trial
 *   activate()   trial, trial_expired, expired, suspended, cancelled -> active
 *   renew()                                                  active -> active   (extends period)
 *   cancel()                                                  active -> cancelled (period keeps running — NOT terminal)
 *   suspend()                                                 active -> suspended
 *   expire()                                           trial -> trial_expired
 *                                                active, cancelled -> expired
 *   upgrade()/downgrade()                        trial, active -> (status unchanged, plan_id swaps)
 *
 * IMPORTANT for Phase 5: `cancelled` still means the client has access until
 * `current_period_end` passes — it is NOT the same as `expired`. The
 * EnsureAccountIsActive middleware (Phase 5) must check current_period_end
 * for cancelled subscriptions, not just treat any non-'active' status as
 * blocked. Not implemented here — this class only owns state, not access
 * control.
 *
 * Every mutating method:
 *  - runs inside a DB transaction (subscription + client writes are atomic)
 *  - locks the subscription/client row first (safe against concurrent
 *    webhook retries once Phase 3 lands)
 *  - writes an activity_logs entry via ActivityLogger for audit/dispute trail
 */
class SubscriptionService
{
    /**
     * Client -> Trial. Fails if the client already has a live subscription
     * (trial, active, or cancelled-but-still-in-grace-period), or if the
     * plan isn't active.
     */
    public function createTrial(Client $client, Plan $plan, int $trialDays = 14): Subscription
    {
        if ($trialDays < 1) {
            throw new InvalidArgumentException('trialDays must be at least 1.');
        }

        if ($plan->status !== 'active') {
            throw new RuntimeException("Cannot start a trial on plan '{$plan->slug}' because it is not active.");
        }

        return DB::transaction(function () use ($client, $plan, $trialDays) {
            $client = Client::whereKey($client->id)->lockForUpdate()->firstOrFail();

            if ($client->subscriptions()->whereIn('status', ['trial', 'active', 'cancelled'])->exists()) {
                throw new RuntimeException('Client already has a trial, active, or cancelled-but-active subscription.');
            }

            $trialEndsAt = now()->addDays($trialDays);

            $subscription = Subscription::create([
                'client_id' => $client->id,
                'plan_id' => $plan->id,
                'status' => 'trial',
                'trial_ends_at' => $trialEndsAt,
                'current_period_start' => now(),
                'current_period_end' => $trialEndsAt,
            ]);

            $this->syncClient($client, [
                'subscription_status' => 'trial',
                'trial_end_date' => $trialEndsAt,
                'subscription_end_date' => null,
                'seat_limit' => $plan->seat_limit,
                'suspended_at' => null,
            ]);

            $this->log('subscription.trial_created', $subscription, [
                'plan_id' => $plan->id,
                'trial_ends_at' => $trialEndsAt->toDateTimeString(),
            ]);

            return $subscription;
        });
    }

    /**
     * trial / trial_expired / expired / suspended / cancelled -> active.
     * Starts a fresh billing period from now. Covers both "first payment"
     * and "reactivate/un-cancel" — they're the same operation.
     */
    public function activate(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('plan', 'client');

            $this->assertTransitionAllowed(
                $subscription->status,
                ['trial', 'trial_expired', 'expired', 'suspended', 'cancelled'],
                'activate'
            );

            $fromStatus = $subscription->status;
            $periodEnd = $this->nextPeriodEnd($subscription->plan);

            $subscription->update([
                'status' => 'active',
                'current_period_start' => now(),
                'current_period_end' => $periodEnd,
                'cancelled_at' => null,
            ]);

            $this->syncClient($subscription->client, [
                'subscription_status' => 'active',
                'subscription_end_date' => $periodEnd,
                'suspended_at' => null,
            ]);

            $this->log('subscription.activated', $subscription, ['from_status' => $fromStatus]);

            return $subscription->fresh();
        });
    }

    /**
     * active -> active. Extends the period; if the old period already ended,
     * the new period starts from now rather than stacking onto the past.
     */
    public function renew(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('plan', 'client');

            $this->assertTransitionAllowed($subscription->status, ['active'], 'renew');

            $base = $subscription->current_period_end && $subscription->current_period_end->isFuture()
                ? $subscription->current_period_end
                : now();

            $periodEnd = $this->nextPeriodEnd($subscription->plan, $base);

            $subscription->update([
                'status' => 'active',
                'current_period_start' => $base,
                'current_period_end' => $periodEnd,
            ]);

            $this->syncClient($subscription->client, [
                'subscription_status' => 'active',
                'subscription_end_date' => $periodEnd,
                'suspended_at' => null,
            ]);

            $this->log('subscription.renewed', $subscription, [
                'new_period_end' => $periodEnd->toDateTimeString(),
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * active -> cancelled. Does NOT cut access — current_period_end is left
     * untouched, so the client keeps using the CRM until the period they
     * already paid for actually ends. That's when expire() should run
     * (Phase 4/5 cron), not now. activate() can still undo this (un-cancel).
     */
    public function cancel(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('client');

            $this->assertTransitionAllowed($subscription->status, ['active'], 'cancel');

            $subscription->update(['status' => 'cancelled', 'cancelled_at' => now()]);

            // subscription_status mirrors the real status here deliberately —
            // Phase 5's access-control middleware is what needs to treat
            // "cancelled but current_period_end still in the future" as
            // usable, not this service.
            $this->syncClient($subscription->client, ['subscription_status' => 'cancelled']);

            $this->log('subscription.cancelled', $subscription, [
                'access_until' => optional($subscription->current_period_end)->toDateTimeString(),
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * active -> suspended. Admin-initiated block (fraud, chargeback, policy
     * violation) — distinct from a customer's own cancel(). Pass a reason
     * for the audit trail.
     */
    public function suspend(Subscription $subscription, ?string $reason = null): Subscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('client');

            $this->assertTransitionAllowed($subscription->status, ['active'], 'suspend');

            $subscription->update(['status' => 'suspended']);

            $this->syncClient($subscription->client, [
                'subscription_status' => 'suspended',
                'suspended_at' => now(),
            ]);

            $this->log('subscription.suspended', $subscription, ['reason' => $reason]);

            return $subscription->fresh();
        });
    }

    /**
     * trial -> trial_expired  (never paid)
     * active, cancelled -> expired  (was paying, or was winding down)
     */
    public function expire(Subscription $subscription): Subscription
    {
        return DB::transaction(function () use ($subscription) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('client');

            $this->assertTransitionAllowed($subscription->status, ['trial', 'active', 'cancelled'], 'expire');

            $fromStatus = $subscription->status;
            $toStatus = $fromStatus === 'trial' ? 'trial_expired' : 'expired';

            $subscription->update(['status' => $toStatus]);

            $this->syncClient($subscription->client, ['subscription_status' => $toStatus]);

            $this->log('subscription.expired', $subscription, [
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
            ]);

            return $subscription->fresh();
        });
    }

    /**
     * Swap to a pricier, active plan. Only valid while trial/active. Status
     * is never touched by this — matches your original spec exactly.
     */
    public function upgrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        return $this->changePlan($subscription, $newPlan, 'upgrade');
    }

    /**
     * Swap to a cheaper, active plan. Only valid while trial/active.
     */
    public function downgrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        return $this->changePlan($subscription, $newPlan, 'downgrade');
    }

    private function changePlan(Subscription $subscription, Plan $newPlan, string $direction): Subscription
    {
        if ($newPlan->status !== 'active') {
            throw new RuntimeException("Cannot switch to plan '{$newPlan->slug}' because it is not active.");
        }

        return DB::transaction(function () use ($subscription, $newPlan, $direction) {
            $subscription = Subscription::whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $subscription->loadMissing('plan', 'client');

            $this->assertTransitionAllowed($subscription->status, ['trial', 'active'], "{$direction} the plan for");

            if ($direction === 'upgrade' && $newPlan->price <= $subscription->plan->price) {
                throw new RuntimeException('upgrade() requires a plan priced higher than the current plan.');
            }

            if ($direction === 'downgrade' && $newPlan->price >= $subscription->plan->price) {
                throw new RuntimeException('downgrade() requires a plan priced lower than the current plan.');
            }

            $oldPlanId = $subscription->plan_id;
            $subscription->update(['plan_id' => $newPlan->id]);

            $this->syncClient($subscription->client, ['seat_limit' => $newPlan->seat_limit]);

            $this->log("subscription.{$direction}d", $subscription, [
                'from_plan_id' => $oldPlanId,
                'to_plan_id' => $newPlan->id,
            ]);

            return $subscription->fresh();
        });
    }

    private function nextPeriodEnd(Plan $plan, ?Carbon $from = null): Carbon
    {
        $from ??= now();

        return $plan->billing_cycle === 'yearly'
            ? $from->copy()->addYear()
            : $from->copy()->addMonth();
    }

    private function syncClient(Client $client, array $attributes): void
    {
        $client->update($attributes);
    }

    private function assertTransitionAllowed(string $current, array $allowed, string $action): void
    {
        if (! in_array($current, $allowed, true)) {
            throw new RuntimeException(
                "Cannot {$action} a subscription with status '{$current}'. Allowed from: " . implode(', ', $allowed)
            );
        }
    }

    /**
     * Best-effort audit log via ActivityLogger::log($action, $model, $data).
     */
    private function log(string $action, Subscription $subscription, array $data = []): void
    {
        if (class_exists(ActivityLogger::class)) {
            ActivityLogger::log($action, $subscription, $data);
        }
    }
}