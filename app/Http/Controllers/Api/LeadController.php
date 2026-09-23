<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRequest;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\LeadSearch;
use App\Support\Roles;
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
use Illuminate\Database\QueryException;

class LeadController extends Controller
{
    /**
     * Roles that see ALL leads across ALL clients/tenants, and roles that
     * administer leads only within their own client/tenant.
     *
     * Phase 2 Foundation: consolidated into App\Support\Roles, the single
     * source of truth used app-wide, so this file's SUPER_ADMIN_ROLES can
     * never again independently drift to include 'admin' the way it once
     * did — see App\Support\Roles's docblock for that incident. Behavior
     * here is unchanged: 'super_admin' only bypasses tenant isolation;
     * 'admin' and 'client_admin' administer leads within their own tenant.
     */
    private function isSuperAdmin(User $user): bool
    {
        return Roles::isSuperAdmin($user->role);
    }

    private function isClientAdmin(User $user): bool
    {
        return Roles::isTenantAdmin($user->role);
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
            // See App\Support\LeadSearch and PHASE2B-REMEDIATION-REPORT.md §5:
            // fast, index-backed path for name search, guaranteed-correct
            // full scan for anything phone/email-shaped or that the fast
            // path can't confidently answer.
            LeadSearch::apply($request->search, clone $countQuery, $query, $countQuery);
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

        // $totalCount (line 128) already counted this exact scope UNLESS
        // assigned_to/client_id narrowed $query further below (lines 146,
        // 171) — reuse it when the scopes still match instead of letting
        // paginate() run its own second COUNT(*) against the same
        // tenant-filtered `leads` table on every single request (this was
        // previously a silent double-count; see PHASE2B-REMEDIATION-REPORT.md
        // §B). When the scope did diverge, a fresh count is required for a
        // correct pagination total — reusing $totalCount there would report
        // the wrong last page for that narrower view.
        $scopeNarrowedAfterTotalCount = ($request->filled('assigned_to') && $isAnyAdmin)
            || ($this->isSuperAdmin($user) && $request->filled('client_id'));
        $paginationTotal = $scopeNarrowedAfterTotalCount ? (clone $query)->count() : $totalCount;

        // Phase 3 Workstream 02: a negative value used to survive into
        // paginate() and produce an invalid "OFFSET without LIMIT" SQL
        // statement (HTTP 500). The first fix (max(1, min(...))) closed
        // that, but also silently changed per_page=0 from "use the
        // default" (Laravel's own pagination falls back to the default
        // for a falsy 0) to "show 1 result" — a real, if minor, response
        // contract regression caught by the Workstream 02 re-test.
        // Restored: missing OR explicitly 0 both mean "use the default
        // (15)", matching the pre-Workstream-02 behavior exactly; only a
        // genuinely non-zero value gets clamped to the [1, 100] range.
        $rawPerPage = $request->input('per_page');
        $perPage = ($rawPerPage === null || (int) $rawPerPage === 0)
            ? 15
            : max(1, min((int) $rawPerPage, 100));
        $leads = $query->latest()->paginate($perPage, ['*'], 'page', null, $paginationTotal);

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

        try {
            $lead->forceFill($this->filterLeadColumns(array_merge(
                $data,
                ['created_by' => $user->id]
            )))->save();
        } catch (QueryException $e) {
            if ($response = $this->duplicateLeadResponse($e)) {
                return $response;
            }

            throw $e;
        }

        ActivityLogger::log('lead.created', $lead, ['source' => $lead->source]);

        return response()->json([
            'success' => true,
            'message' => 'Lead created successfully.',
            'data' => $lead->load(['assignedTo:id,name', 'createdBy:id,name']),
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $leadId = $this->parseRouteId($id);

        if ($leadId === null) {
            return $this->notFound();
        }

        $lead = $this->findLead($request, $leadId);

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

    public function update(LeadRequest $request, string $id): JsonResponse
    {
        $leadId = $this->parseRouteId($id);

        if ($leadId === null) {
            return $this->notFound();
        }

        $lead = $this->findLead($request, $leadId);

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

        try {
            $lead->forceFill($this->filterLeadColumns($data))->save();
        } catch (QueryException $e) {
            if ($response = $this->duplicateLeadResponse($e)) {
                return $response;
            }

            throw $e;
        }

        $fresh = $lead->fresh(['assignedTo:id,name', 'createdBy:id,name']);

        if (! $fresh) {
            // Phase 3 Workstream 03 finding #3: the lead was deleted by a
            // concurrent request between findLead() above and save() —
            // save() then silently affected 0 rows (Eloquent doesn't check
            // this), so returning success here would falsely claim an
            // update that never took effect. Report the same "not found"
            // outcome a request arriving a moment later would have gotten,
            // instead of success:true with data:null.
            return $this->notFound();
        }

        $this->logActivity($request, 'update', 'Lead', $lead->id, "Updated lead: {$lead->name}");

        return response()->json([
            'success' => true,
            'message' => 'Lead updated successfully.',
            'data' => $fresh,
        ]);
    }

    public function updateStatus(Request $request, string $id): JsonResponse
    {
        $leadId = $this->parseRouteId($id);

        if ($leadId === null) {
            return $this->notFound();
        }

        $request->validate([
            'status' => ['required', Rule::in([
                'new', 'interested', 'followup', 'demo', 'converted', 'closed', 'not_interested',
            ])],
        ]);

        $lead = $this->findLead($request, $leadId);

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

    public function destroy(Request $request, string $id): JsonResponse
    {
        $leadId = $this->parseRouteId($id);

        if ($leadId === null) {
            return $this->notFound();
        }

        $lead = $this->findLead($request, $leadId);

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

    public function assign(Request $request, string $id): JsonResponse
    {
        $leadId = $this->parseRouteId($id);

        if ($leadId === null) {
            return $this->notFound();
        }

        // Workstream 12 finding W12-F02: bare 'exists:users,id' accepts an
        // array value too (Laravel's exists rule validates each element),
        // so a request sending assigned_to as an array/object passed
        // validation, then User::find() received that non-scalar value
        // and returned a Collection instead of a single model — the
        // controller's own client_id/role checks below then failed on
        // that Collection with no such property, producing an uncaught
        // error and a generic 500. 'integer' forces a genuine scalar
        // before 'exists' ever runs, matching this endpoint's actual,
        // always-scalar contract.
        $request->validate([
            'assigned_to' => ['required', 'integer', 'exists:users,id'],
        ]);

        // Tenant-scope the lead lookup itself — previously this used Lead::find($id)
        // with no scoping at all, letting a client_admin assign leads from OTHER tenants.
        $lead = $this->findLead($request, $leadId);

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
     * Workstream 06 finding W06-F01: these routes used to type-hint their
     * route parameter as primitive `int`, which let PHP's own argument
     * coercion throw an uncaught TypeError — before this class's code ever
     * ran — for a non-numeric segment ("abc") or one that's numeric but
     * out of PHP's integer range (a 20-digit overflow value), producing an
     * unhandled 500 instead of the app's normal 404. filter_var(...,
     * FILTER_VALIDATE_INT) is what actually distinguishes those two cases
     * from a valid ID; is_numeric() alone would not, since it accepts an
     * out-of-range digit string just as happily as an in-range one.
     *
     * A route ->where('id', '[0-9]+') constraint was considered instead
     * and rejected: it still lets an overflow value like
     * "99999999999999999999" through (it's all digits, so it matches the
     * regex — the TypeError happens later, from magnitude, not shape), and
     * excluding a leading "-" would also stop /leads/-1 from ever reaching
     * the controller at all, changing its existing "Lead not found." 404
     * into a differently-worded router-level 404 — a behavior change this
     * fix must not introduce.
     */
    private function parseRouteId(string $id): ?int
    {
        $parsed = filter_var($id, FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
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
        return \App\Services\LeadImport\LeadColumnFilter::filter($data);
    }

    /**
     * Workstream 12 finding W12-F01: LeadRules' Rule::unique() checks for
     * phone/email are a check-then-insert pattern with nothing underneath
     * to make that sequence atomic — confirmed live, concurrent identical
     * requests could all pass validation and all insert. The database
     * itself is now the final authority (leads_client_phone_unique /
     * leads_client_email_unique, see the migration this finding
     * introduced), so the *losing* concurrent request now fails here, as
     * a genuine QueryException, instead of never happening at all.
     *
     * Only ever recognizes these two specific, named constraints — never
     * treated as a general "swallow any QueryException" handler. Any
     * other database error (a different constraint, a connection
     * failure, anything unrelated) is deliberately rethrown by the
     * caller and falls through to the app's existing, already-sanitized
     * generic exception handling.
     */
    private function duplicateLeadResponse(QueryException $e): ?JsonResponse
    {
        if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
            return null;
        }

        $message = $e->getMessage();

        // Same message text LeadRules::messages() already uses for the
        // pre-insert Rule::unique() check (the path a sequential
        // duplicate takes) — the race-losing concurrent request should
        // look identical to that, not introduce a second wording for the
        // same rejection.
        if (str_contains($message, 'leads_client_phone_unique')) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => ['phone' => ['This phone number is already used by another lead.']],
            ], 422);
        }

        if (str_contains($message, 'leads_client_email_unique')) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'data' => ['email' => ['This email address is already used by another lead.']],
            ], 422);
        }

        return null;
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

        // OBS-F04 (Phase 1 audit): this used to log the raw webhook body
        // (Log::info(..., $request->all())) wholesale. Reproduced against
        // the actual payload shape Meta sends (see MetaWebhookLeadTest.php)
        // and confirmed the webhook body itself never carries lead PII
        // (name/phone/email) - only routing metadata (leadgen_id/page_id/
        // form_id); the PII arrives separately via fetchMetaLead() below
        // and is never passed to Log:: anywhere in this class. Logging an
        // explicit, allowlisted summary instead of the raw body regardless
        // - defense in depth against a future Meta payload shape change,
        // and it was never useful to have the *whole* body in the log for
        // debugging purposes this summary doesn't already cover.
        Log::info('Meta webhook received.', [
            'entry_count' => count($request->input('entry', [])),
            'leadgen_ids' => collect($request->input('entry', []))
                ->flatMap(fn ($entry) => $entry['changes'] ?? [])
                ->pluck('value.leadgen_id')
                ->filter()
                ->values(),
        ]);

        foreach ($request->input('entry', []) as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                if (($change['field'] ?? null) !== 'leadgen') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $leadgenId = $value['leadgen_id'] ?? null;

                if (! $leadgenId) {
                    Log::warning('Meta webhook leadgen event missing leadgen_id.', [
                        'page_id' => $value['page_id'] ?? null,
                        'form_id' => $value['form_id'] ?? null,
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

        // SEC-F07: fail CLOSED, not open. An unconfigured secret must never
        // be treated as "signature check not required" — that would let
        // anyone POST a fabricated leadgen payload to this public,
        // unauthenticated endpoint and have it written straight into the
        // leads table.
        if (! $appSecret) {
            Log::error('Meta webhook rejected: META_APP_SECRET is not configured.');

            return false;
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
            // OBS-F04: was logging Meta's raw error response body wholesale.
            // A failed fetch never returns lead data (Graph API only
            // returns field_data on success), so this was never actually
            // logging PII - but it's still an unbounded dump of external
            // content. Meta's Graph API error shape is consistently
            // {error: {message, type, code}}; log just that, not the body
            // verbatim.
            $errorBody = $exception->response?->json('error');
            Log::warning('Meta lead fetch failed.', [
                'leadgen_id' => $leadgenId,
                'status' => $exception->response?->status(),
                'error_message' => $errorBody['message'] ?? null,
                'error_code' => $errorBody['code'] ?? null,
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

        // REL-F01: bounded timeout + retry. This is a read-only GET (no
        // side effects on Meta's side), so retrying on a transient
        // connection failure is safe.
        return Http::acceptJson()
            ->timeout(10)->connectTimeout(5)->retry(2, 500)
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