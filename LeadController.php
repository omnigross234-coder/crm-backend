<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LeadRequest;
use App\Models\ActivityLog;
use App\Models\Lead;
use App\Models\User;
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

class LeadController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->role === 'admin';

        $query = Lead::with(['assignedTo:id,name,email', 'createdBy:id,name']);
        $countQuery = Lead::query();

        if (! $isAdmin) {
            $query->where('assigned_to', $user->id);
            $countQuery->where('assigned_to', $user->id);
        }

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
        $userCounts = $isAdmin
            ? (clone $countQuery)
                ->whereNotNull('assigned_to')
                ->selectRaw('assigned_to, count(*) as total')
                ->groupBy('assigned_to')
                ->pluck('total', 'assigned_to')
            : collect([$user->id => $totalCount]);

        if ($request->filled('assigned_to') && $isAdmin) {
            $query->where('assigned_to', $request->assigned_to);
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

        if ($user->role !== 'admin') {
            $data['assigned_to'] = $user->id;
        }

        $lead = new Lead();
        $lead->forceFill($this->filterLeadColumns(array_merge(
            $data,
            ['created_by' => $user->id]
        )))->save();

        $this->logActivity($request, 'create', 'Lead', $lead->id, "Created lead: {$lead->name}");

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
            'data' => $lead->load(['assignedTo:id,name,email', 'createdBy:id,name', 'followups.user:id,name']),
        ]);
    }

    public function update(LeadRequest $request, int $id): JsonResponse
    {
        $lead = $this->findLead($request, $id);

        if (! $lead) {
            return $this->notFound();
        }

        $lead->forceFill($this->filterLeadColumns($request->validated()))->save();

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
                'new', 'interested', 'followup', 'converted', 'closed', 'not_interested',
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

        $lead = Lead::find($id);

        if (! $lead) {
            return $this->notFound();
        }

        $salesUser = User::find($request->assigned_to);
        if ($salesUser->role !== 'sales') {
            return response()->json([
                'success' => false,
                'message' => 'Leads can only be assigned to sales users.',
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

    private function findLead(Request $request, int $id): ?Lead
    {
        $query = Lead::query();
        if ($request->user()->role !== 'admin') {
            $query->where('assigned_to', $request->user()->id);
        }

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
    // admin only
    if ($request->user()->role !== 'admin') {
        abort(403);
    }

    $filters = $request->only(['status', 'priority', 'assigned_to', 'search']);
    return (new LeadsExport($filters))->download();
}

public function storeTestLead(Request $request)
{
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

        'created_by' => 1
    ]);

    return response()->json([
        'success' => true,
        'message' => 'Lead Created',
        'data' => $lead
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
        'success' => true
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
            'created_by' => $createdBy,
            'meta_form_id' => $metaLead['form_id'] ?? $webhookValue['form_id'] ?? null,
            'meta_page_id' => $webhookValue['page_id'] ?? null,
            'meta_ad_id' => $metaLead['ad_id'] ?? $webhookValue['ad_id'] ?? null,
            'meta_platform' => $metaLead['platform'] ?? null,
            'meta_raw_data' => [
                'webhook' => $webhookValue,
                'lead' => $metaLead,
            ],
        ]
    );
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
