<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid credentials.',
                'data'    => null,
            ], 401);
        }

        /** @var User $user */
        $user  = Auth::user();

        if ($user->status === 'inactive') {
            Auth::logout();
            return response()->json([
                'success' => false,
                'message' => 'Your account is inactive.',
                'data'    => null,
            ], 403);
        }

        // A suspended tenant must prevent every linked user (client admin and
        // sales users) from receiving an API token. Super admins do not belong
        // to a client and remain unaffected.
        $user->load('client');
        if (! $user->isSuperAdmin() && $user->client?->status === 'suspended') {
            Auth::logout();

            return response()->json([
                'success' => false,
                'message' => 'Your client account is suspended. Please contact the platform administrator.',
                'data'    => null,
            ], 403);
        }

        $token = $user->createToken('crm-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful.',
            'data'    => [
                'user'  => $user,
                'token' => $token,
                'role'  => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
            'data'    => null,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Authenticated user.',
            'data'    => $request->user(),
        ]);
    }
}
