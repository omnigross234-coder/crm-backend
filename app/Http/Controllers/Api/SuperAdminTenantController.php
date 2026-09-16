<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\Followup;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Phase 5 (Tenant Management): read-only Super Admin tenant list/detail.
 *
 * Mutations are NOT duplicated here — tenant creation/status/admin-contact
 * changes continue to go through the existing `POST /clients` and
 * `PUT /clients/{client}` (ClientController), which already validate,
 * authorize (route-level `super_admin` middleware), audit-log
 * (`client.created`/`client.updated`), and revoke sessions on update. This
 * controller only adds the enriched read views the brief asks for, by
 * aggregating existing data — no new billing/audit logic is introduced.
 *
 * No delete/destroy action is exposed anywhere in this controller or the
 * routes that use it. See the Phase 5 report's "Deletion/data-retention
 * decision" section: `leads.client_id` and `subscriptions.client_id` both
 * CASCADE on client delete, while `users.client_id` only nulls out
 * (orphans, does not delete) — ClientController::destroy() already exists
 * and is left untouched, but deliberately not wired into the new UI.
 */
class SuperAdminTenantController extends Controller
{
    private const SORTABLE = ['name', 'created_at', 'users_count', 'leads_count'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:191',
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
            'plan_id' => 'sometimes|integer|exists:plans,id',
            // 'none' = no trial/active subscription row exists at all.
            'subscription_status' => ['sometimes', Rule::in(['trial', 'active', 'cancelled', 'none'])],
            'trial_only' => 'sometimes|boolean',
            'sort_by' => ['sometimes', 'string', Rule::in(self::SORTABLE)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $sortBy = $validated['sort_by'] ?? 'name';
        $sortDir = $validated['sort_dir'] ?? 'asc';

        $query = Client::query()
            ->withCount(['users', 'leads'])
            ->withMax('leads', 'created_at')
            // No column restriction on adminUser/activeSubscription: both are
            // *OfMany relations (oldestOfMany/latestOfMany) whose internal
            // join subquery breaks with an "ambiguous column name: client_id"
            // error when the outer select list is also restricted — a known
            // Eloquent gotcha, confirmed by reproducing it directly.
            ->with([
                'adminUser',
                'activeSubscription.plan:id,name,price,billing_cycle',
            ]);

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhereHas('adminUser', fn ($uq) => $uq->where('email', 'like', "%{$search}%"));
            });
        }

        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $subscriptionStatus = $validated['subscription_status'] ?? null;
        if ($subscriptionStatus === 'none') {
            $query->whereDoesntHave('activeSubscription');
        } elseif (! empty($validated['plan_id']) || $subscriptionStatus || ! empty($validated['trial_only'])) {
            $query->whereHas('activeSubscription', function ($q) use ($validated, $subscriptionStatus) {
                if (! empty($validated['plan_id'])) {
                    $q->where('plan_id', $validated['plan_id']);
                }
                if ($subscriptionStatus) {
                    $q->where('status', $subscriptionStatus);
                }
                if (! empty($validated['trial_only'])) {
                    $q->where('status', 'trial');
                }
            });
        }

        $query->orderBy($sortBy, $sortDir);

        $clients = $query->paginate($perPage);

        $clients->getCollection()->transform(fn (Client $client) => $this->summarize($client));

        return response()->json([
            'success' => true,
            'data' => $clients->items(),
            'meta' => [
                'current_page' => $clients->currentPage(),
                'per_page' => $clients->perPage(),
                'total' => $clients->total(),
                'last_page' => $clients->lastPage(),
            ],
        ]);
    }

    public function show(Client $client): JsonResponse
    {
        $client->loadCount(['users', 'leads']);
        $client->load(['adminUser', 'activeSubscription.plan']);

        // The most recent subscription row regardless of status, so an
        // expired/cancelled tenant still shows its last known plan/renewal
        // instead of appearing to have never subscribed.
        $latestSubscription = $client->subscriptions()->with('plan')->latest('id')->first();

        $roleDistribution = $client->users()
            ->select('role', DB::raw('count(*) as aggregate'))
            ->groupBy('role')
            ->pluck('aggregate', 'role');

        $activeUsers = $client->users()->where('status', 'active')->count();
        $inactiveUsers = $client->users()->where('status', 'inactive')->count();

        $recentLeads = $client->leads()
            ->latest('created_at')
            ->limit(5)
            ->get(['id', 'name', 'status', 'source', 'created_at']);

        // CallLog/Followup have no client_id column of their own — the same
        // scoping the rest of the app already uses (CallLogController) is
        // reused here: CallLog -> user.client_id, Followup -> lead.client_id.
        $since30d = now()->subDays(30);
        $callCount30d = CallLog::whereHas('user', fn ($q) => $q->where('client_id', $client->id))
            ->where('created_at', '>=', $since30d)
            ->count();
        $followupCount30d = Followup::whereHas('lead', fn ($q) => $q->where('client_id', $client->id))
            ->where('created_at', '>=', $since30d)
            ->count();

        $recentPayments = Payment::whereHas('subscription', fn ($q) => $q->where('client_id', $client->id))
            ->latest('id')
            ->limit(5)
            ->get(['id', 'subscription_id', 'gateway', 'amount', 'status', 'paid_at', 'created_at']);

        return response()->json([
            'success' => true,
            'data' => [
                'client' => $this->summarize($client),
                'admin' => $client->adminUser,
                'users' => [
                    'total' => $client->users_count,
                    'active' => $activeUsers,
                    'inactive' => $inactiveUsers,
                    'by_role' => $roleDistribution,
                ],
                'crm' => [
                    'leads_count' => $client->leads_count,
                    'recent_leads' => $recentLeads,
                    'calls_last_30d' => $callCount30d,
                    'followups_last_30d' => $followupCount30d,
                ],
                'billing' => [
                    'active_subscription' => $client->activeSubscription,
                    'latest_subscription' => $latestSubscription,
                    'recent_payments' => $recentPayments,
                ],
                // Operations: backups in this app are platform-wide only
                // (BackupController::list/run has no per-tenant scope or
                // filter) — there is no real per-tenant backup status to
                // show. Documented here rather than fabricated.
                'operations' => [
                    'per_tenant_backups_supported' => false,
                ],
            ],
        ]);
    }

    private function summarize(Client $client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'slug' => $client->slug,
            'status' => $client->status,
            'created_at' => $client->created_at,
            'users_count' => $client->users_count,
            'leads_count' => $client->leads_count,
            'latest_lead_at' => $client->leads_max_created_at,
            'admin' => $client->adminUser ? [
                'name' => $client->adminUser->name,
                'email' => $client->adminUser->email,
            ] : null,
            'subscription' => $client->activeSubscription ? [
                'status' => $client->activeSubscription->status,
                'trial_ends_at' => $client->activeSubscription->trial_ends_at,
                'current_period_end' => $client->activeSubscription->current_period_end,
                'plan' => $client->activeSubscription->plan ? [
                    'id' => $client->activeSubscription->plan->id,
                    'name' => $client->activeSubscription->plan->name,
                    'price' => $client->activeSubscription->plan->price,
                    'billing_cycle' => $client->activeSubscription->plan->billing_cycle,
                ] : null,
            ] : null,
        ];
    }
}
