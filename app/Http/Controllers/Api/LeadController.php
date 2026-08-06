<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRequest;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use App\Exports\LeadsExport;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Builder;

class LeadController extends Controller
{
    /**
     * Roles that see ALL leads across ALL clients/tenants.
     * Keep this list tiny and audited — it bypasses tenant isolation entirely.
     */
    private const SUPER_ADMIN_ROLES = ['admin'];

    /**
     * Roles that administer leads, but ONLY within their own client/tenant.
     */
    private const CLIENT_ADMIN_ROLES = ['client_admin'];

    private function isSuperAdmin(User $user): bool
    {
        return in_array($user->role, self::SUPER_ADMIN_ROLES, true);
    }

    private function isClientAdmin(User $user): bool
    {
        return in_array($user->role, self::CLIENT_ADMIN_ROLES, true);
    }

    /**
     * True if the user can see/manage every lead within their own tenant
     * (client_admin), as opposed to only leads assigned to them (sales).
     */
    private function isAnyAdmin(User $user): bool
    {
        return $this->isSuperAdmin($user) || $this->isClientAdmin($user);
    }

    /**
     * Apply tenant + ownership scoping to a Lead query based on the acting user.
     * This is the single source of truth for "who can see which leads" —
     * every method below MUST route through this instead of hand-rolling scopes.
     */
    private function scopeLeadsForUser(Builder $query, User $user): Builder
    {
        if ($this->isSuperAdmin($user)) {
            // True super-admin: no scoping at all.
            return $query;
        }

        if ($this->isClientAdmin($user)) {
            // Client admin: all leads within their own tenant only.
            return $query->where('client_id', $user->client_id);
        }

        // Regular sales / sales_employee: only their own leads, still tenant-scoped
        // as defense in depth in case assigned_to is ever misassigned cross-tenant.
        return $query
            ->where('assigned_to', $user->id)
            ->where('client_id', $user->client_id);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAnyAdmin = $this->isAnyAdmin($user);

        $query = Lead::with(['assignedTo:id,name,email', 'createdBy:id,name'])
            ->withCount('callLogs');
        $countQuery = Lead::query();

        $this->scopeLeadsForUser($query, $user);
        $this->scopeLeadsForUser($countQuery, $user);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
            $countQuery->where('status', $request->status);
        }
        if ($request->filled('priority')) {
            $query->where('priority', $request->priority);
            $countQuery->where('priority', $request->priority);
        }
        if ($request->boolean('followups_today')) {
            $query->whereHas('followups', function ($q) {
                $q->whereDate('next_followup_date', today())
                    ->where('status', 'pending');
            });
            $countQuery->whereHas('followups', function ($q) {
                $q->whereDate('next_followup_date', today())
                    ->where('status', 'pending');
            });
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
            $countQuery->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $totalCount = (clone $countQuery)->count();

        // user_counts must also stay within the same tenant — never leak
        // per-user counts from other clients even for a super-admin's UI
        // unless a client_id filter is explicitly requested.
        $userCountsQuery = clone $countQuery;
        if ($isAnyAdmin && $request->filled('assigned_to') === false && $this->isSuperAdmin($user) && $request->filled('client_id')) {
            $userCountsQuery->where('client_id', $request->input('client_id'));
        }

        $userCounts = $isAnyAdmin
            ? $userCountsQuery
                ->whereNotNull('assigned_to')
                ->selectRaw('assigned_to, count(*) as total')
                ->groupBy('assigned_to')
                ->pluck('total', 'assigned_to')
            : collect([$user->id => $totalCount]);

        if ($request->filled('assigned_to') && $isAnyAdmin) {
            $assignedTo = (int) $request->assigned_to;

            // Guard: a client_admin must not be able to pass assigned_to
            // belonging to a user from a different tenant to peek at their leads.
            if ($this->isClientAdmin($user)) {
                $belongsToTenant = User::whereKey($assignedTo)
                    ->where('client_id', $user->client_id)
                    ->exists();

                if (! $belongsToTenant) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Invalid assigned_to for this account.',
                        'data' => null,
                    ], 422);
                }
            }

            $query->where('assigned_to', $assignedTo);
        }

        // Super-admin only: allow explicit cross-tenant filtering via client_id,
        // otherwise a super-admin's default view is intentionally "everything".
        if ($this->isSuperAdmin($user) && $request->filled('client_id')) {
            $query->where('client_id', $request->input('client_id'));
        }

        $perPage = min((int) $request->input('per_page', 15), 100);
        $leads = $query->latest()->paginate($perPage);

        return response()->json([
            'success' => true,
            'message' => 'Leads retrieved.',
            'data' => $leads,
            'meta' => [
                'total_count' => $totalCount,
                'user_counts' => $userCounts,
            ],
        ]);
    }

    public function store(LeadRequest $request): JsonResponse
    {
        $data = $request->validated();
        $user = $request->user();

        if (! $this->isAnyAdmin($user)) {
            $data['assigned_to'] = $user->id;
        }

        // Tenant stamping: a lead always belongs to the creating user's client,
        // regardless of what (if anything) the client sent in the payload.
        // Super-admins may explicitly target a tenant via client_id in the payload;
        // everyone else is force-pinned to their own tenant.
        if ($this->isSuperAdmin($user)) {
            $data['client_id'] = $data['client_id'] ?? $user->client_id;
        } else {
            $data['client_id'] = $user->client_id;
        }

        // If assigned_to was supplied, make sure it belongs to the same tenant.
        if (! empty($data['assigned_to'])) {
            $assignee = User::find($data['assigned_to']);
            if (! $assignee || $assignee->client_id !== $data['client_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'assigned_to must belong to the same client.',
                    'data' => null,
                ], 422);
            }
        }

        $lead = new Lead();
        $lead->forceFill($this->filterLeadColumns(array_merge(
            $data,
            ['created_by' => $user->id]
        )))->save();

        ActivityLogger::log('lead.created', $lead, ['source' => $lead->source]);

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully.',
            'data' => $lead->load(['assignedTo:id,name', 'createdBy:id,name']),
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        return response()->json([
            'success' => true,
            'message' => 'Lead retrieved.',
            'data' => $lead
                ->load(['assignedTo:id,name,email', 'createdBy:id,name', 'followups.user:id,name'])
                ->loadCount('callLogs'),
        ]);
    }

    public function update(LeadRequest $request, int $id): JsonResponse
    {
        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        $data = $request->validated();

        // Never allow client_id or assigned_to-cross-tenant hijack via update payload.
        unset($data['client_id']);
        if (array_key_exists('assigned_to', $data) && $data['assigned_to']) {
            $assignee = User::find($data['assigned_to']);
            if (! $assignee || $assignee->client_id !== $lead->client_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'assigned_to must belong to the same client.',
                    'data' => null,
                ], 422);
            }
        }

        $lead->forceFill($this->filterLeadColumns($data))->save();

        $this->logActivity($request, 'update', 'Lead', $lead->id, "Updated lead: {$lead->name}");

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully.',
            'data' => $lead->fresh(['assignedTo:id,name', 'createdBy:id,name']),
        ]);
    }

    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'status' => ['required', Rule::in([
                'new', 'interested', 'followup', 'demo', 'converted', 'closed', 'not_interested',
            ])],
        ]);

        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        $lead->update(['status' => $request->status]);

        $this->logActivity($request, 'update', 'Lead', $lead->id, "Status updated to: {$request->status}");

        return response()->json([
            'success' => true,
            'message' => 'Status updated.',
            'data' => ['status' => $lead->status],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        $this->logActivity($request, 'delete', 'Lead', $lead->id, "Deleted lead: {$lead->name}");
        $lead->delete();

        return response()->json([
            'success' => true,
            'message' => 'Lead deleted successfully.',
            'data' => null,
        ]);
    }

    public function assign(Request $request, int $id): JsonResponse
    {
        $request->validate([
            'assigned_to' => ['required', 'exists:users,id'],
        ]);

        // Tenant-scope the lead lookup itself — previously this used Lead::find($id)
        // with no scoping at all, letting a client_admin assign leads from OTHER tenants.
        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        $salesUser = User::find($request->assigned_to);
        if (
            ! $salesUser ||
            $salesUser->client_id !== $lead->client_id ||
            ! in_array($salesUser->role, ['sales', 'sales_employee'], true)
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Leads can only be assigned to sales users in your client account.',
                'data' => null,
            ], 422);
        }

        $lead->update(['assigned_to' => $request->assigned_to]);

        $this->logActivity($request, 'assign', 'Lead', $lead->id, "Assigned lead {$lead->name} to {$salesUser->name}");

        return response()->json([
            'success' => true,
            'message' => 'Lead assigned successfully.',
            'data' => $lead->fresh('assignedTo:id,name'),
        ]);
    }

    /**
     * Central lookup used by show/update/updateStatus/destroy/assign.
     * Always routes through scopeLeadsForUser so tenant + ownership rules
     * are enforced identically everywhere a single lead is fetched by ID.
     */
    private function findLead(Request $request, int $id): ?Lead
    {
        $query = Lead::query();
        $this->scopeLeadsForUser($query, $request->user());

        return $query->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Lead not found.',
            'data' => null,
        ], 404);
    }

    private function filterLeadColumns(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn('leads', $key))
            ->all();
    }

    private function logActivity(Request $request, string $action, string $module, int $recordId, string $description): void
    {
        ActivityLog::create([
            'user_id' => $request->user()->id,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'description' => $description,
            'ip_address' => $request->ip(),
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $user = $request->user();

        if (! $this->isAnyAdmin($user)) {
            abort(403);
        }

        $filters = $request->only(['status', 'priority', 'assigned_to', 'search']);

        // Super-admin may export a single tenant via client_id, otherwise
        // exports are always scoped to the acting user's own tenant.
        $filters['client_id'] = $this->isSuperAdmin($user)
            ? ($request->input('client_id') ?? null)
            : $user->client_id;

        return (new LeadsExport($filters))->download();
    }

    /**
     * DEBUG/TESTING ONLY. This endpoint has no meaningful auth guard beyond
     * whatever the route middleware applies, hardcodes created_by, and never
     * set client_id in the original version — meaning any caller could create
     * leads with no tenant, invisible to every dashboard/list query.
     *
     * Recommendation: remove this route entirely before shipping to production.
     * If you must keep it for internal Postman testing, restrict the route to
     * super-admin + local/staging environment, as done below.
     */
    public function storeTestLead(Request $request)
    {
        $user = $request->user();

        if (! app()->environment(['local', 'staging']) || ! $this->isSuperAdmin($user)) {
            abort(403, 'Test lead creation is disabled in this environment.');
        }

        $lead = Lead::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'email' => $request->email,
            'company' => $request->company,
            'address' => $request->address,

            'source' => 'social',
            'status' => 'new',
            'priority' => 'warm',

            'remarks' => 'Test Lead From Postman',

            'client_id' => $request->input('client_id', $user->client_id),
            'created_by' => $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Lead Created',
            'data' => $lead,
        ]);
    }

    public function verifyWebhook(Request $request)
    {
        if (
            $request->get('hub_mode') === 'subscribe' &&
            hash_equals((string) config('services.meta.verify_token'), (string) $request->get('hub_verify_token'))
        ) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Verification failed', 403);
    }

    public function receiveWebhook(Request $request)
    {
        if (! $this->hasValidMetaSignature($request)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid Meta signature.',
            ], 403);
        }

        Log::info('Meta webhook received.', $request->all());

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $leadgenId = $value['leadgen_id'] ?? null;

                if (! $leadgenId) {
                    Log::warning('Meta webhook leadgen event missing leadgen_id.', [
                        'change' => $change,
                    ]);
                    continue;
                }

                $this->storeMetaLead($leadgenId, $value);
            }
        }

        return response()->json([
            'success' => true,
        ]);
    }

    private function hasValidMetaSignature(Request $request): bool
    {
        $appSecret = config('services.meta.app_secret');

        if (! $appSecret) {
            return true;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');

        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }

    private function storeMetaLead(string $leadgenId, array $webhookValue): void
    {
        $pageAccessToken = config('services.meta.page_access_token');

        if (! $pageAccessToken) {
            Log::error('Meta page access token is not configured.');
            return;
        }

        try {
            $metaLead = $this->fetchMetaLead($leadgenId, $pageAccessToken);
        } catch (RequestException $exception) {
            Log::warning('Meta lead fetch failed.', [
                'leadgen_id' => $leadgenId,
                'status' => $exception->response?->status(),
                'response' => $exception->response?->json(),
            ]);
            return;
        }

        $fields = $this->metaFieldData($metaLead['field_data'] ?? []);
        $createdBy = $this->metaCreatedByUserId();

        if (! $createdBy) {
            Log::error('Meta lead could not be saved because no CRM user exists for created_by.', [
                'leadgen_id' => $leadgenId,
            ]);
            return;
        }

        $pageId = $webhookValue['page_id'] ?? null;
        $clientId = $this->resolveClientIdForPage($pageId);

        if (! $clientId) {
            // Refuse to create an untenanted lead — this previously would have
            // silently created a lead with no client_id, invisible to any
            // client_admin's scoped queries and orphaned in the DB.
            Log::error('Meta lead could not be saved: no client mapped to this Meta page.', [
                'leadgen_id' => $leadgenId,
                'page_id' => $pageId,
            ]);
            return;
        }

        $name = $this->firstMetaField($fields, ['full_name', 'name'])
            ?? trim(collect([
                $this->firstMetaField($fields, ['first_name']),
                $this->firstMetaField($fields, ['last_name']),
            ])->filter()->implode(' '));

        Lead::updateOrCreate(
            ['meta_leadgen_id' => $leadgenId],
            [
                'name' => $name ?: 'Meta Lead',
                'phone' => $this->firstMetaField($fields, ['phone_number', 'phone', 'mobile_number']),
                'email' => $this->firstMetaField($fields, ['email']),
                'company' => $this->firstMetaField($fields, ['company_name', 'company']),
                'city' => $this->firstMetaField($fields, ['city']),
                'state' => $this->firstMetaField($fields, ['state', 'province']),
                'country' => $this->firstMetaField($fields, ['country']),
                'pin_code' => $this->firstMetaField($fields, ['zip_code', 'postal_code', 'pin_code']),
                'requirement' => $this->firstMetaField($fields, ['requirement', 'message', 'comments']),
                'source' => 'social',
                'status' => 'new',
                'priority' => 'warm',
                'remarks' => 'Lead from Meta webhook',
                'client_id' => $clientId,
                'created_by' => $createdBy,
                'meta_form_id' => $metaLead['form_id'] ?? $webhookValue['form_id'] ?? null,
                'meta_page_id' => $pageId,
                'meta_ad_id' => $metaLead['ad_id'] ?? $webhookValue['ad_id'] ?? null,
                'meta_platform' => $metaLead['platform'] ?? null,
                'meta_raw_data' => [
                    'webhook' => $webhookValue,
                    'lead' => $metaLead,
                ],
            ]
        );
    }

    /**
     * TODO: wire this up to however Meta Page IDs map to your `clients` table
     * (e.g. a `client_meta_pages` pivot, or a `meta_page_id` column on `clients`).
     * Until this is implemented correctly, leads from unmapped pages are
     * intentionally dropped (see storeMetaLead) rather than created untenanted.
     */
    private function resolveClientIdForPage(?string $pageId): ?int
    {
        if (! $pageId) {
            return null;
        }

        // Example once you have the mapping table:
        // return \App\Models\Client::where('meta_page_id', $pageId)->value('id');

        return null;
    }

    private function fetchMetaLead(string $leadgenId, string $pageAccessToken): array
    {
        $version = config('services.meta.graph_version', 'v20.0');

        return Http::acceptJson()
            ->get("https://graph.facebook.com/{$version}/{$leadgenId}", [
                'access_token' => $pageAccessToken,
                'fields' => 'id,created_time,ad_id,form_id,field_data,platform',
            ])
            ->throw()
            ->json();
    }

    private function metaFieldData(array $fieldData): Collection
    {
        return collect($fieldData)->mapWithKeys(function (array $field): array {
            $name = $field['name'] ?? null;

            if (! $name) {
                return [];
            }

            return [$name => $field['values'][0] ?? null];
        });
    }

    private function firstMetaField(Collection $fields, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $fields->get($name);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    private function metaCreatedByUserId(): ?int
    {
        $configuredUserId = (int) config('services.meta.created_by_user_id', 1);

        if (User::whereKey($configuredUserId)->exists()) {
            return $configuredUserId;
        }

        $fallbackUserId = User::query()->orderBy('id')->value('id');

        return $fallbackUserId ? (int) $fallbackUserId : null;
    }
}