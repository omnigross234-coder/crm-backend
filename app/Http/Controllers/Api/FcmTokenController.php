<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FcmTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['nullable', 'string', Rule::in(['android', 'ios', 'web'])],
        ]);

        $request->user()->forceFill([
            'fcm_token' => $data['token'],
        ])->save();

        return response()->json([
            'success' => true,
            'message' => 'FCM token saved.',
            'data' => null,
        ]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $user = $request->user();

        if ($user->fcm_token === $token) {
            $user->forceFill(['fcm_token' => null])->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'FCM token removed.',
            'data' => null,
        ]);
    }
}
