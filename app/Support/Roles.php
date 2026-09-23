<?php

namespace App\Support;

/**
 * Single source of truth for the role strings used across the app.
 *
 * This does NOT change any role value in the database or any
 * authorization outcome — it's a consolidation of the same literal
 * arrays that were previously duplicated (and had drifted) across
 * LeadController, CallLogController, DashboardController,
 * FollowupController, BelongsToClient, and EnsureSuperAdmin.
 *
 * LeadController's own history is why this matters: SUPER_ADMIN_ROLES
 * there once wrongly included 'admin', letting a client_admin grant one
 * of their own tenant's users full cross-tenant access. Centralizing the
 * grouping here means that class of drift can only happen in one place.
 */
final class Roles
{
    public const SUPER_ADMIN = 'super_admin';
    public const CLIENT_ADMIN = 'client_admin';
    public const ADMIN = 'admin';
    public const SALES = 'sales';
    public const SALES_EMPLOYEE = 'sales_employee';
    public const SALES_MANAGER = 'sales_manager';

    /**
     * Roles that see ALL leads/data across ALL clients/tenants.
     * Keep this list tiny and audited — it bypasses tenant isolation
     * entirely. Matches BelongsToClient's global scope and EnsureSuperAdmin.
     */
    public const SUPER_ADMIN_ROLES = [self::SUPER_ADMIN];

    /**
     * Roles that administer their own client/tenant only — 'admin' is a
     * tenant-scoped admin role (additional staff promoted within a
     * tenant), 'client_admin' is the tenant's primary/owner admin. Both
     * are equivalent for authorization purposes everywhere in this app.
     */
    public const TENANT_ADMIN_ROLES = [self::ADMIN, self::CLIENT_ADMIN];

    /**
     * Non-admin tenant staff roles. 'sales' is the value actually
     * assignable today via UserController/UserRequest; 'sales_employee'
     * and 'sales_manager' exist in the schema/User model helpers but are
     * not currently reachable through any API-facing creation path.
     */
    public const SALES_ROLES = [self::SALES, self::SALES_EMPLOYEE, self::SALES_MANAGER];

    public const ALL_ROLES = [
        self::SUPER_ADMIN,
        self::CLIENT_ADMIN,
        self::ADMIN,
        self::SALES,
        self::SALES_EMPLOYEE,
        self::SALES_MANAGER,
    ];

    public static function isSuperAdmin(?string $role): bool
    {
        return in_array($role, self::SUPER_ADMIN_ROLES, true);
    }

    public static function isTenantAdmin(?string $role): bool
    {
        return in_array($role, self::TENANT_ADMIN_ROLES, true);
    }
}
