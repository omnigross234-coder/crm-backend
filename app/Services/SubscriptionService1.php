<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Owns every subscription state transition. No payment gateway calls here —
 * Phase 3 wires Razorpay in front of activate()/renew(), it doesn't touch
 * this class's internals.
 */
class SubscriptionService
{
    /**
     * Client → Trial. Fails if the client already has a live subscription.
     */
    public function createTrial(Client $client, Plan $plan, int $trialDays = 14): Subscription
    {
        if ($client->subscriptions()->whereIn('status', ['trial', 'active'])->exists()) {
            throw new RuntimeException('Client already has an active or trial subscription.');
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

        return $subscription;
    }

    /**
     * Trial/Expired/Suspended → Active. Starts a fresh billing period from now.
     */
    public function activate(Subscription $subscription): Subscription
    {
        $this->assertTransitionAllowed($subscription->status, ['trial', 'expired', 'suspended'], 'activate');

        $subscription->loadMissing('plan', 'client');
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

        return $subscription->fresh();
    }

    /**
     * Active/Expired → Active. Extends the period; if the old period already
     * ended, the new period starts from now rather than stacking onto the past.
     */
    public function renew(Subscription $subscription): Subscription
    {
        $this->assertTransitionAllowed($subscription->status, ['active', 'expired'], 'renew');

        $subscription->loadMissing('plan', 'client');

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

        return $subscription->fresh();
    }

    /**
     * Trial/Active/Suspended → Expired.
     */
    public function expire(Subscription $subscription): Subscription
    {
        $this->assertTransitionAllowed($subscription->status, ['trial', 'active', 'suspended'], 'expire');

        $subscription->update(['status' => 'expired']);

        $this->syncClient($subscription->client, [
            'subscription_status' => 'expired',
        ]);

        return $subscription->fresh();
    }

    /**
     * Trial/Active/Expired/Suspended → Cancelled. Terminal — nothing
     * transitions out of 'cancelled' in this service.
     */
    public function cancel(Subscription $subscription): Subscription
    {
        $this->assertTransitionAllowed($subscription->status, ['trial', 'active', 'expired', 'suspended'], 'cancel');

        $subscription->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
        ]);

        $this->syncClient($subscription->client, [
            'subscription_status' => 'cancelled',
        ]);

        return $subscription->fresh();
    }

    /**
     * Swap to a pricier plan. Only valid while trial/active.
     */
    public function upgrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        $subscription->loadMissing('plan');

        if ($newPlan->price <= $subscription->plan->price) {
            throw new RuntimeException('upgrade() requires a plan priced higher than the current plan.');
        }

        return $this->changePlan($subscription, $newPlan);
    }

    /**
     * Swap to a cheaper plan. Only valid while trial/active.
     */
    public function downgrade(Subscription $subscription, Plan $newPlan): Subscription
    {
        $subscription->loadMissing('plan');

        if ($newPlan->price >= $subscription->plan->price) {
            throw new RuntimeException('downgrade() requires a plan priced lower than the current plan.');
        }

        return $this->changePlan($subscription, $newPlan);
    }

    private function changePlan(Subscription $subscription, Plan $newPlan): Subscription
    {
        $this->assertTransitionAllowed($subscription->status, ['trial', 'active'], 'change the plan for');

        $subscription->update(['plan_id' => $newPlan->id]);

        $this->syncClient($subscription->client, [
            'seat_limit' => $newPlan->seat_limit,
        ]);

        return $subscription->fresh();
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
}
