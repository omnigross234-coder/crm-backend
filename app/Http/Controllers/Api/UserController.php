<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::select('id', 'name', 'email', 'phone', 'role', 'status', 'created_at')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Users retrieved.',
            'data' => $users,
        ]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'phone' => $request->phone,
            'role' => $request->input('role', 'sales'),
            'status' => $request->input('status', 'active'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created.',
            'data' => $user,
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['nullable', Rule::in(['admin', 'sales'])],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'password' => ['nullable', 'string', 'min:8'],
        ]);

        $data = $request->only('name', 'email', 'phone', 'role', 'status');

        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }

        $user->update($data);

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'data' => $user->fresh(),
        ]);
    }

    // public function toggleStatus(User $user): JsonResponse
    // {
    //     if ($user->id === auth()->id()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Cannot change your own status.',
    //             'data'    => null,
    //         ], 403);
    //     }

    //     $user->update([
    //         'status' => $user->status === 'active' ? 'inactive' : 'active',
    //     ]);

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Status updated.',
    //         'data'    => ['id' => $user->id, 'status' => $user->status],
    //     ]);
    // }
    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change your own status.',
                'data' => null,
            ], 403);
        }

        $user->update([
            'status' => $user->status === 'active' ? 'inactive' : 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Status updated.',
            'data' => ['id' => $user->id, 'status' => $user->status],
        ]);
    }
    // public function destroy(User $user): JsonResponse
    // {
    //     if ($user->id === auth()->id()) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Cannot delete yourself.',
    //             'data'    => null,
    //         ], 403);
    //     }

    //     $user->delete();

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'User deleted.',
    //         'data'    => null,
    //     ]);
    // }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete yourself.',
                'data' => null,
            ], 403);
        }

        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'User deleted.',
            'data' => null,
        ]);
    }
}
