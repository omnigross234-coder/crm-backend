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
            'called_at' => 'required|date',
            'duration_seconds' => 'required|integer|min:0',
            'is_connected' => 'required|boolean',
            'status' => 'sometimes|in:calling,completed,not_connected',
            'ended_at' => 'nullable|date|after_or_equal:called_at',
            'android_call_log_id' => 'nullable|string|max:191',
        ]);

        $log = CallLog::create([
            'user_id' => auth()->id(),
            'lead_id' => $data['lead_id'],
            'called_at' => $data['called_at'],
            'duration_seconds' => $data['duration_seconds'],
            'is_connected' => $data['is_connected'],
            'status' => $data['status']
                ?? ($data['is_connected'] ? 'completed' : 'not_connected'),
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

        if ($user->role !== 'admin' && $callLog->user_id !== $user->id) {
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
            'status' => 'required|in:completed,not_connected',
            'ended_at' => 'required|date|after_or_equal:'.$callLog->called_at->toIso8601String(),
            'android_call_log_id' => 'nullable|string|max:191',
            'sms_sent' => 'sometimes|boolean',
            'whatsapp_sent' => 'sometimes|boolean',
        ]);

        $expectedStatus = $data['is_connected'] ? 'completed' : 'not_connected';
        if ($data['status'] !== $expectedStatus) {
            return response()->json([
                'success' => false,
                'message' => 'Call status does not match its connection result.',
                'errors' => [
                    'status' => ['Use completed for connected calls and not_connected otherwise.'],
                ],
            ], 422);
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

    // GET /api/call-logs  ← your existing index (completely unchanged)
    public function index(Request $request)
    {
        $query = CallLog::query();

        if ($request->filled('date')) {
            $query->whereDate('called_at', $request->date);
        } elseif ($request->filled('month')) {
            [$y, $m] = explode('-', $request->month);
            $query->whereYear('called_at', $y)->whereMonth('called_at', $m);
        }

        $summary = (clone $query)
            ->where('is_connected', true)
            ->select(
                'user_id',
                DB::raw('SUM(duration_seconds) as total_seconds'),
                DB::raw('COUNT(*) as call_count')
            )
            ->groupBy('user_id')
            ->with('user:id,name')
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->user_id,
                'user_name' => $row->user->name ?? '',
                'total_seconds' => (int) $row->total_seconds,
                'call_count' => (int) $row->call_count,
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
}
