<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Followup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowupReminderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reminders = Followup::query()
            ->where('user_id', $request->user()->id)
            ->where('status', 'pending')
            ->whereNotNull('reminder_ready_at')
            ->whereNull('reminder_acknowledged_at')
            ->with('lead:id,name,phone,email,company,status,priority')
            ->orderBy('next_followup_datetime')
            ->limit(10)
            ->get([
                'id',
                'lead_id',
                'user_id',
                'note',
                'next_followup_datetime',
                'reminder_ready_at',
            ]);

        return response()->json(['success' => true, 'data' => $reminders]);
    }

    public function acknowledge(Request $request, Followup $followup): JsonResponse
    {
        if (
            $followup->user_id !== $request->user()->id ||
            $followup->status !== 'pending' ||
            $followup->reminder_ready_at === null
        ) {
            return response()->json([
                'success' => false,
                'message' => 'Reminder not found.',
                'data' => null,
            ], 404);
        }

        // Idempotent acknowledgement prevents repeat popups after retries.
        if ($followup->reminder_acknowledged_at === null) {
            $followup->forceFill(['reminder_acknowledged_at' => now()])->save();
        }

        return response()->json([
            'success' => true,
            'data' => ['followup_id' => $followup->id],
        ]);
    }
}
