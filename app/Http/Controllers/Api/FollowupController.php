<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowupRequest;
use App\Models\ActivityLog;
use App\Models\Followup;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FollowupController extends Controller
{
    public function index(Request $request, int $leadId): JsonResponse
    {
        $lead = $this->findLead($request, $leadId);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data'    => null,
            ], 404);
        }

        $followups = $lead->followups()->with('user:id,name')->latest()->get();

        return response()->json([
            'success' => true,
            'message' => 'Followups retrieved.',
            'data'    => $followups,
        ]);
    }

    public function store(FollowupRequest $request, int $leadId): JsonResponse
    {
        $lead = $this->findLead($request, $leadId);

        if (!$lead) {
            return response()->json([
                'success' => false,
                'message' => 'Lead not found.',
                'data'    => null,
            ], 404);
        }

        $data = array_merge(
            $request->validated(),
            [
                'lead_id'       => $lead->id,
                'user_id'       => $request->user()->id,
                'reminder_sent' => false,
            ]
        );

        // Keep date column in sync when datetime is provided
        if (!empty($data['next_followup_datetime'])) {
            $data['next_followup_date'] = date('Y-m-d', strtotime($data['next_followup_datetime']));
        }

        $followup = Followup::create($data);

        if ($request->filled('next_followup_datetime') || $request->filled('next_followup_date')) {
            $lead->update(['status' => 'followup']);
        }

        ActivityLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'create',
            'module'      => 'Followup',
            'record_id'   => $followup->id,
            'description' => "Added followup for lead: {$lead->name}",
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Followup created.',
            'data'    => $followup->load('user:id,name'),
        ], 201);
    }

    private function findLead(Request $request, int $leadId): ?Lead
    {
        $query = Lead::query();
        if ($request->user()->role !== 'admin') {
            $query->where('assigned_to', $request->user()->id);
        }
        return $query->find($leadId);
    }
}