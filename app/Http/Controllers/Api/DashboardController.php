<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function stats(Request $request): JsonResponse
    {
        $user    = $request->user();
        $isAdmin = $user->role === 'admin';

        $leadsQuery = Lead::query();
        if (!$isAdmin) {
            $leadsQuery->where('assigned_to', $user->id);
        }

        $totalLeads     = (clone $leadsQuery)->count();
        $newLeads       = (clone $leadsQuery)->where('status', 'new')->count();
        $followupLeads  = (clone $leadsQuery)->where('status', 'followup')->count();
        $convertedLeads = (clone $leadsQuery)->where('status', 'converted')->count();

        $todaysFollowups = (clone $leadsQuery)
            ->whereHas('followups', function ($query) {
                $query->whereDate('next_followup_date', today())
                    ->where('status', 'pending');
            })
            ->count();

        $recentLeadsQuery = Lead::with(['assignedTo:id,name'])->latest()->limit(5);
        if (!$isAdmin) {
            $recentLeadsQuery->where('assigned_to', $user->id);
        }

        $data = [
            'total_leads'      => $totalLeads,
            'new_leads'        => $newLeads,
            'followup_leads'   => $followupLeads,
            'converted'        => $convertedLeads,
            'followups_today'  => $todaysFollowups,
            'recent_leads'     => $recentLeadsQuery->get(),
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard stats.',
            'data'    => $data,
        ]);
    }
}
