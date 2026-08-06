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
        $user = $request->user();
        $isSuperAdmin = $user->role === 'admin';
        $isClientAdmin = $user->role === 'client_admin';

        $leadsQuery = Lead::query();

        if ($isClientAdmin) {
            // Client admin sees all leads within their own client/tenant only.
            $leadsQuery->where('client_id', $user->client_id);
        } elseif (! $isSuperAdmin) {
            // Sales/sales_employee sees only leads assigned to them.
            $leadsQuery->where('assigned_to', $user->id)
                ->where('client_id', $user->client_id);
        }
        // else: true super-admin sees everything, no scope applied.

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

        $recentLeads = (clone $leadsQuery)
            ->with(['assignedTo:id,name'])
            ->latest()
            ->limit(5)
            ->get();

        $data = [
            'total_leads'     => $totalLeads,
            'new_leads'       => $newLeads,
            'followup_leads'  => $followupLeads,
            'converted'       => $convertedLeads,
            'followups_today' => $todaysFollowups,
            'recent_leads'    => $recentLeads,
        ];

        return response()->json([
            'success' => true,
            'message' => 'Dashboard stats.',
            'data'    => $data,
        ]);
    }
}