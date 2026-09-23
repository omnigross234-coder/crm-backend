<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Lead;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallLogController extends Controller
{
    // Retained for older app builds. Device messaging replaces Twilio.
    public function store(Request $request, Lead $lead): JsonResponse
    {
        // SEC-F01: this legacy path had no ownership check at all — a sales
        // user could log a call against any lead in their own tenant, not
        // just one assigned to them. Mirrors the check storeManual() already
        // applies. Lead's own global scope (BelongsToClient) already keeps
        // this to leads within the caller's tenant via route-model binding.
        //
        // CallLog Write-Path Super Admin Remediation: the ownership check
        // below did not exempt super_admin (Roles::isTenantAdmin() is false
        // for super_admin, same defect class already fixed in
        // FollowupController::findLead()) — a super_admin was wrongly
        // required to have the lead assigned to their own user id, which is
        // essentially never true, so every write from a super_admin 403'd.
        // Fixed to match LeadController::scopeLeadsForUser()'s hierarchy:
        // super_admin and tenant admins get no ownership narrowing; every
        // other role still does. Lead's global scope already grants
        // super_admin platform-wide reach to the lead itself; this only
        // changes who additionally needs `assigned_to === $user->id`.
        $user = $request->user();
        if (! $this->isClientAdministrator($user) && ! Roles::isSuperAdmin($user->role) && $lead->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You may only record calls for leads assigned to you.',
            ], 403);
        }

        CallLog::create([
            'user_id' => $request->user()->id,
            'lead_id' => $lead->id,
            'direction' => 'outgoing',
            'called_at' => now(),
            'duration_seconds' => 0,
            'is_connected' => false,
            'sms_sent' => false,
            'whatsapp_sent' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Call recorded. Follow-up messaging is handled by the Android app.',
        ]);
    }

    // POST /api/call-logs  ← your existing manual log (unchanged)
    public function storeManual(Request $request): JsonResponse
    {
        $this->normalizeAndroidCallLogId($request);

        $data = $request->validate([
            'lead_id' => 'required|exists:leads,id',
            'direction' => 'sometimes|in:incoming,outgoing',
            'called_at' => 'required|date',
            'duration_seconds' => 'required|integer|min:0',
            'is_connected' => 'required|boolean',
            'status' => 'sometimes|in:calling,completed,not_connected,missed',
            'ended_at' => 'nullable|date|after_or_equal:called_at',
            'android_call_log_id' => 'nullable|string|max:191',
        ]);

        $user = $request->user();
        $lead = Lead::findOrFail($data['lead_id']);
        // See the matching comment in store() above — same fix, same reason.
        if (! $this->isClientAdministrator($user) && ! Roles::isSuperAdmin($user->role) && $lead->assigned_to !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You may only record calls for leads assigned to you.',
            ], 403);
        }

        $direction = $data['direction'] ?? 'outgoing';
        $status = $data['status']
            ?? ($data['is_connected']
                ? 'completed'
                : ($direction === 'incoming' ? 'missed' : 'not_connected'));

        if (! $this->hasValidResult($direction, $status, $data['is_connected'], $data['duration_seconds'])) {
            return response()->json([
                'success' => false,
                'message' => 'Call status does not match its direction and connection result.',
            ], 422);
        }

        if (! empty($data['android_call_log_id'])) {
            $existing = CallLog::where('user_id', $user->id)
                ->where('android_call_log_id', $data['android_call_log_id'])
                ->first();
            if ($existing) {
                return response()->json([
                    'success' => true,
                    'message' => 'Call was already recorded.',
                    'data' => $existing,
                ]);
            }
        }

        $log = CallLog::create([
            'user_id' => $user->id,
            'lead_id' => $data['lead_id'],
            'direction' => $direction,
            'called_at' => $data['called_at'],
            'duration_seconds' => $data['duration_seconds'],
            'is_connected' => $data['is_connected'],
            'status' => $status,
            'ended_at' => $data['ended_at'] ?? null,
            'android_call_log_id' => $data['android_call_log_id'] ?? null,
            'sms_sent' => false,
            'whatsapp_sent' => false,
        ]);

        return response()->json(['success' => true, 'data' => $log]);
    }

    public function update(Request $request, CallLog $callLog): JsonResponse
    {
        $this->normalizeAndroidCallLogId($request);

        $user = $request->user();

        // CallLog Update Super Admin Remediation: this tenant-boundary check
        // compared the call log owner's client_id against the CALLER's own
        // client_id — correct for every tenant-scoped role, but a
        // super_admin's own client_id is always null, so this always
        // failed for any real (tenant-scoped) call log, returning 404 for
        // a record that genuinely exists. Reproduced live before this fix:
        // a super_admin updating a real call log got 404 "Call record not
        // found.", while the identical request succeeded once the call
        // log's owner also happened to have client_id = null. Fixed by
        // exempting super_admin from this check entirely, matching
        // CallLogController::index()'s existing super_admin branch (no
        // tenant filter applied) and LeadController::scopeLeadsForUser()'s
        // hierarchy (super_admin: no scoping at all).
        $belongsToClient = Roles::isSuperAdmin($user->role) || $callLog->user()
            ->where('client_id', $user->client_id)
            ->exists();
        if (! $belongsToClient) {
            abort(404, 'Call record not found.');
        }

        // Same defect class, second check: isClientAdministrator() is false
        // for super_admin (Roles::isTenantAdmin() excludes it), so without
        // this exemption a super_admin who cleared the check above would
        // still be blocked here unless they happened to own the record
        // themselves — the exact pattern already fixed in store() and
        // storeManual() above.
        if (! $this->isClientAdministrator($user) && ! Roles::isSuperAdmin($user->role) && $callLog->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You may only update your own call records.',
            ], 403);
        }

        if ($callLog->status !== 'calling') {
            return response()->json([
                'success' => true,
                'message' => 'Call record was already completed.',
                'data' => $callLog->fresh(),
            ]);
        }

        $data = $request->validate([
            'duration_seconds' => 'required|integer|min:0',
            'is_connected' => 'required|boolean',
            'status' => 'required|in:completed,not_connected,missed',
            'ended_at' => 'required|date|after_or_equal:'.$callLog->called_at->toIso8601String(),
            'android_call_log_id' => 'nullable|string|max:191',
            'sms_sent' => 'sometimes|boolean',
            'whatsapp_sent' => 'sometimes|boolean',
        ]);

        if (! $this->hasValidResult(
            $callLog->direction,
            $data['status'],
            $data['is_connected'],
            $data['duration_seconds']
        )) {
            return response()->json([
                'success' => false,
                'message' => 'Call status does not match its connection result.',
            ], 422);
        }

        if (! empty($data['android_call_log_id'])) {
            $duplicate = CallLog::where('user_id', $callLog->user_id)
                ->where('android_call_log_id', $data['android_call_log_id'])
                ->whereKeyNot($callLog->id)
                ->exists();
            if ($duplicate) {
                return response()->json([
                    'success' => false,
                    'message' => 'This device call was already recorded.',
                ], 409);
            }
        }

        $callLog->update($data);

        return response()->json([
            'success' => true,
            'data' => $callLog->fresh(),
        ]);
    }

    private function normalizeAndroidCallLogId(Request $request): void
    {
        if ($request->filled('android_call_log_id')) {
            $request->merge([
                'android_call_log_id' => (string) $request->input('android_call_log_id'),
            ]);
        }
    }

    private function hasValidResult(
        string $direction,
        string $status,
        bool $isConnected,
        int $durationSeconds
    ): bool {
        if ($status === 'calling') {
            return $direction === 'outgoing' && ! $isConnected && $durationSeconds === 0;
        }
        if ($isConnected) {
            return $status === 'completed' && $durationSeconds > 0;
        }
        if ($durationSeconds !== 0) {
            return false;
        }

        return $direction === 'incoming'
            ? $status === 'missed'
            : $status === 'not_connected';
    }

    // GET /api/call-logs
    public function index(Request $request)
    {
        $user = $request->user();
        $isSuperAdmin = Roles::isSuperAdmin($user->role);
        $query = CallLog::query();

        if ($isSuperAdmin) {
            // Phase 2 Foundation fix: previously this always filtered to
            // `whereHas('user', client_id = $user->client_id)`, but a true
            // super_admin has client_id === null — so this endpoint
            // silently returned an empty/near-empty result set for them
            // instead of the platform-wide visibility every other admin
            // surface (Lead, Dashboard) already grants super_admin. Fixed
            // by giving super_admin an explicit, unscoped-by-default branch
            // with an optional, validated ?client_id= to narrow to one
            // tenant — never inferred from anything the client can forge
            // without it existing in the clients table.
            if ($request->filled('client_id')) {
                $request->validate(['client_id' => 'integer|exists:clients,id']);
                $targetClientId = $request->integer('client_id');
                $query->whereHas('user', fn ($userQuery) =>
                    $userQuery->where('client_id', $targetClientId)
                );
            }
        } else {
            // Unchanged for every other role: strictly the caller's own tenant.
            $query->whereHas('user', fn ($userQuery) =>
                $userQuery->where('client_id', $user->client_id)
            );
        }

        if ($this->isClientAdministrator($user) || $isSuperAdmin) {
            if ($request->filled('user_id')) {
                $query->where('user_id', $request->integer('user_id'));
            }
        } else {
            $query->where('user_id', $user->id);
        }

        if ($request->filled('date')) {
            $query->whereDate('called_at', $request->date);
        } elseif ($request->filled('month')) {
            [$y, $m] = explode('-', $request->month);
            $query->whereYear('called_at', $y)->whereMonth('called_at', $m);
        }
        if ($request->filled('direction')) {
            $request->validate(['direction' => 'in:incoming,outgoing']);
            $query->where('direction', $request->string('direction'));
        }

        $summary = (clone $query)
            ->where('is_connected', true)
            ->select(
                'user_id',
                DB::raw('SUM(duration_seconds) as total_seconds'),
                DB::raw('COUNT(*) as call_count'),
                DB::raw("SUM(CASE WHEN direction = 'incoming' THEN duration_seconds ELSE 0 END) as incoming_seconds"),
                DB::raw("SUM(CASE WHEN direction = 'incoming' THEN 1 ELSE 0 END) as incoming_count"),
                DB::raw("SUM(CASE WHEN direction = 'outgoing' THEN duration_seconds ELSE 0 END) as outgoing_seconds"),
                DB::raw("SUM(CASE WHEN direction = 'outgoing' THEN 1 ELSE 0 END) as outgoing_count")
            )
            ->groupBy('user_id')
            ->with('user:id,name')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'user_name' => $row->user->name ?? '',
                'total_seconds' => (int) $row->total_seconds,
                'call_count' => (int) $row->call_count,
                'incoming_seconds' => (int) $row->incoming_seconds,
                'incoming_count' => (int) $row->incoming_count,
                'outgoing_seconds' => (int) $row->outgoing_seconds,
                'outgoing_count' => (int) $row->outgoing_count,
            ]);

        $logs = (clone $query)
            ->with(['user:id,name', 'lead:id,name'])
            ->latest('called_at')
            ->limit(200)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'summary' => $summary,
                'logs' => $logs,
            ],
        ]);
    }

    private function isClientAdministrator($user): bool
    {
        return Roles::isTenantAdmin($user->role);
    }
}
