<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use RuntimeException;

/**
 * Phase 6 (Super Admin User Management): cross-tenant user management,
 * entirely separate from the existing tenant-scoped UserController (which
 * remains untouched and continues to serve client_admin/admin exactly as
 * before). Mirrors the established Phase 4/5 pattern: a new controller
 * under the existing `super_admin` route-middleware group.
 *
 * ROLE ASSIGNMENT (verified, not invented — see the Phase 6 audit report
 * and this phase's own report for the full investigation): `sales_manager`
 * is deliberately EXCLUDED from every assignable-role list below.
 * LeadController::assign() (`POST /leads/{id}/assign`) hard-codes
 * `in_array($salesUser->role, ['sales', 'sales_employee'], true)` —
 * omitting sales_manager — so a sales_manager user created today could not
 * actually be assigned new leads through the app's normal admin workflow.
 * That is a pre-existing, unrelated authorization gap in LeadController,
 * out of this phase's scope to fix (it would be "modifying permission
 * semantics merely to enable a role," explicitly disallowed). Until that
 * gap is fixed in its own dedicated pass, exposing sales_manager here
 * would let an operator create accounts with silently broken assignment
 * behavior. sales_employee has no such gap anywhere in the codebase
 * (confirmed by inspection) and IS exposed.
 *
 * PASSWORD HANDLING: Super Admin never sets or sees a user's password.
 * A newly created user gets a random, unusable, immediately-hashed
 * password and is emailed a reset link via the EXISTING self-service
 * mechanism (PasswordResetController::issueResetToken(), extracted from
 * forgotPassword() for this reuse). The same action is available on
 * demand for any existing user via sendPasswordReset().
 */
class SuperAdminUserController extends Controller
{
    /**
     * Roles assignable through this Super Admin surface. Deliberately
     * excludes `sales_manager` — see the class doc comment above.
     */
    private const ASSIGNABLE_ROLES = [
        Roles::SUPER_ADMIN,
        Roles::CLIENT_ADMIN,
        Roles::ADMIN,
        Roles::SALES,
        Roles::SALES_EMPLOYEE,
    ];

    private const SORTABLE_COLUMNS = ['name', 'email', 'role', 'status', 'created_at'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'search' => 'sometimes|string|max:191',
            'role' => ['sometimes', Rule::in(Roles::ALL_ROLES)],
            'client_id' => 'sometimes|integer|exists:clients,id',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
            'sort_by' => ['sometimes', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $query = User::query()->with('client:id,name,status');

        if (! empty($validated['search'])) {
            $search = $validated['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }
        if (! empty($validated['role'])) {
            $query->where('role', $validated['role']);
        }
        if (! empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }
        if (! empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }
        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }

        $query->orderBy($sortBy, $sortDir);

        $users = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function show(User $user): JsonResponse
    {
        $user->load('client:id,name,status');

        return response()->json(['success' => true, 'data' => $user]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'phone' => 'nullable|string|max:20',
            'role' => ['required', Rule::in(self::ASSIGNABLE_ROLES)],
            'client_id' => 'nullable|integer|exists:clients,id',
            'status' => ['sometimes', Rule::in(['active', 'inactive'])],
        ]);

        $roleTenantError = $this->validateRoleTenantCompatibility($data['role'], $data['client_id'] ?? null);
        if ($roleTenantError) {
            return $roleTenantError;
        }

        $status = $data['status'] ?? 'active';
        $clientId = $data['role'] === Roles::SUPER_ADMIN ? null : $data['client_id'];

        try {
            $user = DB::transaction(function () use ($data, $clientId, $status) {
                if ($clientId && $status === 'active') {
                    $client = Client::whereKey($clientId)->lockForUpdate()->firstOrFail();
                    if (! $client->hasAvailableSeat()) {
                        throw new RuntimeException(
                            "Seat limit reached ({$client->seat_limit} seats on this tenant's plan). "
                            .'Deactivate a user on that tenant, or create this user as inactive.'
                        );
                    }
                }

                return User::create([
                    'client_id' => $clientId,
                    'name' => $data['name'],
                    'email' => $data['email'],
                    // Never chosen by anyone, never returned, never usable as
                    // typed — the account is only ever accessed after the
                    // owner completes the same secure self-service reset
                    // flow every other password change in this app uses.
                    'password' => \Illuminate\Support\Str::random(40),
                    'phone' => $data['phone'] ?? null,
                    'role' => $data['role'],
                    'status' => $status,
                ]);
            });
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null], 422);
        }

        ActivityLogger::log('user.created', $user, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'client_id' => $user->client_id,
        ]);

        PasswordResetController::issueResetToken($user);
        ActivityLogger::log('user.password_reset_initiated', $user, ['trigger' => 'account_creation']);

        return response()->json([
            'success' => true,
            'message' => 'User created. A password setup email has been sent.',
            'data' => $user->fresh()->load('client:id,name,status'),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => 'nullable|string|max:20',
            'role' => ['sometimes', Rule::in(self::ASSIGNABLE_ROLES)],
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $newRole = $data['role'] ?? $user->role;
        $roleChanging = array_key_exists('role', $data) && $data['role'] !== $user->role;
        $clientIdChanging = array_key_exists('client_id', $data) && $data['client_id'] !== $user->client_id;

        $selfProtectionError = $this->guardSelfProtection($actor, $user, [
            'role' => $roleChanging ? $newRole : null,
            'client_id' => $clientIdChanging ? true : null,
        ]);
        if ($selfProtectionError) {
            return $selfProtectionError;
        }

        if ($roleChanging || $clientIdChanging) {
            $targetClientId = $clientIdChanging ? $data['client_id'] : $user->client_id;
            $roleTenantError = $this->validateRoleTenantCompatibility($newRole, $targetClientId);
            if ($roleTenantError) {
                return $roleTenantError;
            }
        }

        $lastAdminError = $this->guardLastActiveSuperAdmin($user, roleChangingAwayFromSuperAdmin: $roleChanging && $newRole !== Roles::SUPER_ADMIN);
        if ($lastAdminError) {
            return $lastAdminError;
        }

        $finalClientId = $newRole === Roles::SUPER_ADMIN ? null : ($clientIdChanging ? $data['client_id'] : $user->client_id);

        $before = $user->only(['name', 'email', 'phone', 'role', 'client_id']);
        $update = array_intersect_key($data, array_flip(['name', 'email', 'phone']));
        $update['role'] = $newRole;
        $update['client_id'] = $finalClientId;

        // Moving an ACTIVE user into a different, tenant-owning role/tenant
        // increases that tenant's active-seat usage — same locked check
        // already used for reactivation everywhere else in this app.
        $movingActiveUserToNewTenant = $user->status === 'active' && $finalClientId && $finalClientId !== $user->client_id;

        try {
            if ($movingActiveUserToNewTenant) {
                DB::transaction(function () use ($user, $update, $finalClientId) {
                    $client = Client::whereKey($finalClientId)->lockForUpdate()->firstOrFail();
                    if (! $client->hasAvailableSeat()) {
                        throw new RuntimeException(
                            "Seat limit reached ({$client->seat_limit} seats on the target tenant's plan)."
                        );
                    }
                    $user->update($update);
                });
            } else {
                $user->update($update);
            }
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null], 422);
        }

        $after = $user->fresh()->only(['name', 'email', 'phone', 'role', 'client_id']);
        if ($before !== $after) {
            ActivityLogger::log('user.updated', $user, ['before' => $before, 'after' => $after]);
        }

        return response()->json([
            'success' => true,
            'message' => 'User updated.',
            'data' => $user->fresh()->load('client:id,name,status'),
        ]);
    }

    public function toggleStatus(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();
        $newStatus = $user->status === 'active' ? 'inactive' : 'active';

        $selfProtectionError = $this->guardSelfProtection($actor, $user, [
            'status' => $newStatus === 'inactive' ? 'inactive' : null,
        ]);
        if ($selfProtectionError) {
            return $selfProtectionError;
        }

        $lastAdminError = $this->guardLastActiveSuperAdmin($user, deactivating: $newStatus === 'inactive');
        if ($lastAdminError) {
            return $lastAdminError;
        }

        if ($newStatus === 'active') {
            if ($user->client_id) {
                try {
                    DB::transaction(function () use ($user) {
                        $client = Client::whereKey($user->client_id)->lockForUpdate()->firstOrFail();
                        if (! $client->hasAvailableSeat()) {
                            throw new RuntimeException(
                                "Seat limit reached ({$client->seat_limit} seats on this tenant's plan)."
                            );
                        }
                        $user->update(['status' => 'active']);
                    });
                } catch (RuntimeException $e) {
                    return response()->json(['success' => false, 'message' => $e->getMessage(), 'data' => null], 422);
                }
            } else {
                $user->update(['status' => 'active']);
            }
        } else {
            $user->update(['status' => 'inactive']);
            $user->tokens()->delete();
        }

        ActivityLogger::log("user.{$newStatus}", $user, ['name' => $user->name]);

        return response()->json([
            'success' => true,
            'message' => "User {$newStatus}.",
            'data' => ['id' => $user->id, 'status' => $user->fresh()->status],
        ]);
    }

    /**
     * "Send password reset" — the only password-related action exposed to
     * Super Admin. Reuses the exact same secure mechanism the self-service
     * forgot-password flow uses; never generates, displays, or accepts a
     * password directly.
     */
    public function sendPasswordReset(User $user): JsonResponse
    {
        PasswordResetController::issueResetToken($user);

        ActivityLogger::log('user.password_reset_initiated', $user, ['trigger' => 'super_admin_action']);

        return response()->json([
            'success' => true,
            'message' => "A password reset email has been sent to {$user->email}.",
            'data' => null,
        ]);
    }

    private function validateRoleTenantCompatibility(string $role, ?int $clientId): ?JsonResponse
    {
        if ($role === Roles::SUPER_ADMIN) {
            if ($clientId !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'A super_admin account cannot belong to a tenant.',
                    'data' => null,
                ], 422);
            }

            return null;
        }

        if ($clientId === null) {
            return response()->json([
                'success' => false,
                'message' => 'A tenant is required for this role.',
                'data' => null,
            ], 422);
        }

        return null;
    }

    /**
     * @param array{role?: string|null, client_id?: bool|null, status?: string|null} $changes
     *   Each key present (non-null) means that field is being changed to
     *   the given value ('client_id' is just a boolean flag — the actual
     *   target tenant doesn't matter for this guard, only that it's moving).
     */
    private function guardSelfProtection(User $actor, User $target, array $changes): ?JsonResponse
    {
        if ($actor->id !== $target->id) {
            return null;
        }

        if (! empty($changes['status'])) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot deactivate your own account.',
                'data' => null,
            ], 403);
        }

        if (array_key_exists('role', $changes) && $changes['role'] !== null && $changes['role'] !== Roles::SUPER_ADMIN) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own role.',
                'data' => null,
            ], 403);
        }

        if (! empty($changes['client_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot change your own tenant assignment.',
                'data' => null,
            ], 403);
        }

        return null;
    }

    /**
     * Blocks any mutation that would leave zero users with
     * role=super_admin AND status=active. Applies regardless of whether
     * the target is the caller themselves (already separately blocked by
     * guardSelfProtection, which is stricter) or a different super_admin
     * account.
     */
    private function guardLastActiveSuperAdmin(
        User $target,
        bool $roleChangingAwayFromSuperAdmin = false,
        bool $deactivating = false
    ): ?JsonResponse {
        $targetIsCurrentlyActiveSuperAdmin = $target->role === Roles::SUPER_ADMIN && $target->status === 'active';

        if (! $targetIsCurrentlyActiveSuperAdmin) {
            return null;
        }

        if (! $roleChangingAwayFromSuperAdmin && ! $deactivating) {
            return null;
        }

        $activeSuperAdminCount = User::where('role', Roles::SUPER_ADMIN)->where('status', 'active')->count();

        if ($activeSuperAdminCount <= 1) {
            return response()->json([
                'success' => false,
                'message' => 'This is the last active Super Admin account on the platform. It cannot be demoted or deactivated.',
                'data' => null,
            ], 422);
        }

        return null;
    }
}
