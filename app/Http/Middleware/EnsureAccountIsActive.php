<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated.'
            ], 401);
        }

        // User disabled
        if ($user->status !== 'active') {

            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Your account has been disabled.'
            ], 403);
        }

        // Super Admin bypass
        if ($user->isSuperAdmin()) {
            return $next($request);
        }

        $user->load('client');

        // Client suspended
        if (!$user->client || $user->client->status !== 'active') {

            $user->currentAccessToken()?->delete();

            return response()->json([
                'success' => false,
                'message' => 'Your client account is suspended.'
            ], 403);
        }

        return $next($request);
    }
}