<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class IdentifyTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        if ($user->role !== 'super_admin' && !$user->client_id) {
            return response()->json(['success' => false, 'message' => 'No client assigned to this account'], 403);
        }

        if ($user->client && $user->client->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'This account has been suspended'], 403);
        }

        // Make it available anywhere via app('tenant_id')
        app()->instance('tenant_id', $user->client_id);

        return $next($request);
    }
}
