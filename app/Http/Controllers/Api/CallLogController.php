<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CallLog;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CallLogController extends Controller
{
    // Retained for older app builds. Device messaging replaces Twilio.
    public function store(Request $request, Lead $lead): JsonResponse
    {
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
        if (! $this->isClientAdministrator($user) && $lead->assigned_to !== $user->id) {
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

        $belongsToClient = $callLog->user()
            ->where('client_id', $user->client_id)
            ->exists();
        if (! $belongsToClient) {
            abort(404, 'Call record not found.');
        }

        if (! $this->isClientAdministrator($user) && $callLog->user_id !== $user->id) {
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

    // GET /api/call-logs  ← your existing index (completely unchanged)
    public function index(Request $request)
    {
        $user = $request->user();
        $query = CallLog::query()
            ->whereHas('user', fn ($userQuery) =>
                $userQuery->where('client_id', $user->client_id)
            );

        if ($this->isClientAdministrator($user)) {
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
        return in_array($user->role, ['admin', 'client_admin'], true);
    }
}
