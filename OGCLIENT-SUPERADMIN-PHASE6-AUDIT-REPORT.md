# OGCLIENT — Super Admin Phase 6: User Management — Read-Only Audit

**Date:** 2026-09-16
**Status:** Audit only. **No code has been written or modified.** Per the brief, implementation begins only after this audit is explicitly reviewed and approved.

All findings below are **verified facts** (read directly from the current code and a live query against the real database) unless explicitly marked **Recommendation**.

---

## 1. What Already Exists

### Backend
- **`User` model** (`app/Models/User.php`): `HasApiTokens` (Sanctum), fillable `client_id, name, email, password, role, phone, profile_photo, status, fcm_token`. `password` uses Laravel's automatic `'hashed'` cast (bcrypt on assignment, no manual `Hash::make()` needed at the model layer — though the existing controller calls `Hash::make()` explicitly anyway, which is harmless/redundant, not a bug). Role helper methods (`isSuperAdmin()`, `isClientAdmin()`, `isTenantAdmin()`, `isSalesManager()`, `isSalesEmployee()`) already exist and delegate to the canonical `App\Support\Roles`.
- **`UserController`** (`app/Http/Controllers/Api/UserController.php`): full CRUD (`index`, `store`, `update`, `toggleStatus`, `destroy`) — but **entirely tenant-scoped to the caller's own `client_id`**, with no super_admin awareness anywhere. Every method either filters by `where('client_id', $request->user()->client_id)` or calls a private `ensureSameClient()` guard that `abort(404)`s unless `$user->client_id === $request->user()->client_id` (and requires the caller to *have* a non-null `client_id` at all).
- **`UserRequest`** (`app/Http/Requests/UserRequest.php`): validates `name`, `email` (globally unique, not per-tenant), `password` (required on create, nullable ≥8 chars on update), `phone`, `role` (**`Rule::in(['admin', 'sales'])` only**), `status` (`active`/`inactive`).
- **Routes**: `GET/POST /users`, `PUT/DELETE /users/{user}`, `PATCH /users/{user}/toggle-status` — all inside `Route::middleware('role:admin,client_admin')->group(...)`. **`super_admin` is not in that list.**
- **`EnsureRole` middleware**: exact `in_array($user->role, $roles, true)` check, no implicit super_admin bypass of any kind.
- **Password reset**: `PasswordResetController` — fully self-service only (`forgotPassword`/`resetPassword`, email + hashed token in `password_reset_tokens`, 60-minute expiry, revokes all the target's tokens on success). No admin-initiated reset path exists anywhere in the codebase today.
- **Admin-initiated password change**: `UserController::update()` already lets a `client_admin`/`admin` directly set another user's password (optional `password` field), and already revokes that user's Sanctum tokens when it does (Workstream 08 fix, well-tested in `UserPasswordChangeRevokesTokensTest.php`).
- **Seat-limit enforcement**: `Client::hasAvailableSeat()`/`activeUserCount()` — every reactivation-to-active transition (`store()` with `status=active`, `update()` doing inactive→active, `toggleStatus()` doing inactive→active) is guarded by a locked, transactional seat-limit check against `Client::seat_limit`.
- **Audit logging infrastructure**: `ActivityLogger::log(action, subject, meta)` writes to `activity_logs` (already used by `ClientController`, `SuperAdminPlanController`, etc.), and `GET /audit-logs` (`AuditLogController`) already supports `resource=` + `subject_id=` + `client_id=` filtering (extended in the Phase 5 workstream). **`UserController` calls `ActivityLogger::log()` nowhere — the existing tenant-scoped user management is entirely unaudited today.**
- **Canonical roles** (`App\Support\Roles`): `SUPER_ADMIN`, `CLIENT_ADMIN`, `ADMIN`, `SALES`, `SALES_EMPLOYEE`, `SALES_MANAGER`; `TENANT_ADMIN_ROLES = [ADMIN, CLIENT_ADMIN]`; helpers `isSuperAdmin()`/`isTenantAdmin()`.
- **`SuperAdminTenantController`** (Phase 5) and its `admin/tenants` route group inside the existing `Route::middleware('super_admin')->group(...)` block — the established architectural pattern for every prior Super Admin surface (Phase 3 dashboard, Phase 4 billing, Phase 5 tenants). Phase 6 would naturally extend this same group.

### Frontend
- **`/admin/users` page** (`app/admin/users/page.tsx`): a **tenant-scoped** client-admin console — create/edit/toggle-status/delete, calling the same `/users` endpoints described above. It contains `isSuperAdmin`-conditional branches (e.g. a role `<select>` offering `client_admin` as an option) that **are unreachable in practice**: the page's own guard is `if (!isAdmin) router.replace("/dashboard")`, and `isAdmin` in `lib/auth.tsx` is `role === 'client_admin' || role === 'admin'` — **super_admin is excluded**, so a super_admin is redirected away before that branch could ever render. This is dead/vestigial code, not a working feature.
- **Navigation** (`components/Layout.tsx`): the "Users" link lives in `clientAdminItems`, rendered only in the client-admin sidebar branch. The super_admin sidebar branch (`[...platformItems, ...superAdminOnlyItems]`) has **no user-management entry at all** today.
- **`lib/auth.tsx`**: `isSuperAdmin = role === 'super_admin'`; `isAdmin`/`isClientAdmin = role === 'client_admin' || role === 'admin'` (these two booleans are mutually exclusive by construction — a super_admin is neither).
- **`lib/api.ts`**: `User` interface exists (`id, name, email, role, client_id?, phone, status`) but its `role` union (`"super_admin" | "client_admin" | "admin" | "sales"`) doesn't include `sales_employee`/`sales_manager` — consistent with them not being reachable via any existing API today.
- **Reusable primitives already established and proven** (Phases 3–5): `Pagination`, `StatusBadge`, `ConfirmDialog`, `PasswordInput`, the dark-theme-first Tailwind token system, the server-side search/filter/paginate page pattern (`/admin/clients`, `/admin/billing/subscriptions`), and the static-export `[id]` detail-page pattern (`page.tsx` wrapper + `generateStaticParams` placeholder + `.htaccess` rewrite rules + `usePathname()`-parsed real ID).

## 2. What Can Be Reused

- The entire Phase 4/5 architectural pattern: a new `SuperAdminUserController` inside the existing `Route::middleware('super_admin')->group(...)` block, under a new `admin/users` prefix (mirroring `admin/tenants`, `admin/billing`) — **not** `users`, which is already owned by the tenant-scoped `UserController`.
- `ActivityLogger::log()` for every new mutation — no new audit system needed.
- `AuditLogController`'s existing `resource=`/`subject_id=`/`client_id=` filters for a user detail page's "Security & Activity" panel (same pattern as Phase 5's tenant detail page). **Note:** `AuditLogController::RESOURCE_TYPES` currently only maps `client`, `lead`, `subscription`, `facebookpage` — it would need `user => \App\Models\User::class` added for this to work (a small, additive change, same shape as the Phase 5 `subject_id` addition).
- `Client::hasAvailableSeat()`/`activeUserCount()` for any Super-Admin-initiated activation/creation that increases a tenant's active-seat count — the exact same transactional, locked pattern `UserController` already uses.
- The admin-initiated password-change-with-token-revocation pattern already in `UserController::update()` — reusable as-is for a Super Admin equivalent, rather than inventing a second reset mechanism.
- Frontend: `Pagination`, `StatusBadge`, `ConfirmDialog`, `PasswordInput`, and the `/admin/clients` + `/admin/clients/[id]` page pair as the direct structural template for a new `/admin/users` (repurposed) list + `/admin/users/[id]` detail pair.
- `SuperAdminTenantController::index()`'s `withCount`/eager-loading query-construction style as the template for an efficient, N+1-free user list query (e.g. eager-loading `client:id,name` per row).

## 3. What Is Missing

- **No backend route, controller, or authorization path lets `super_admin` see, create, edit, or manage a user in any tenant other than none at all** — confirmed by both the `role:admin,client_admin` route-middleware restriction (excludes super_admin outright) and the tenant-scoped queries inside `UserController` itself (a super_admin's own `client_id` is `null`, so `ensureSameClient()` would `abort(404)` against literally any real target).
- No cross-tenant user list/search/filter endpoint.
- No user detail endpoint beyond the tenant-scoped list.
- No Super-Admin-initiated password reset/change mechanism.
- No audit logging on any existing user mutation (tenant-scoped or otherwise) — this is a pre-existing gap, not something Phase 6 breaks.
- No frontend page, route, or navigation entry reachable by a super_admin for user management (the existing dead code in `/admin/users` doesn't count).
- No "last super_admin" protection anywhere in the codebase (not needed until Super Admin gains the ability to change another super_admin's status/role, which doesn't exist today).
- `AuditLogController::RESOURCE_TYPES` has no `user` entry.

## 4. Existing Authorization Boundaries

| Boundary | Mechanism | Verified behavior |
|---|---|---|
| Route-level, tenant user management | `role:admin,client_admin` middleware (`EnsureRole`) | Exact `in_array` match; super_admin explicitly excluded, gets 403 |
| Route-level, existing Super Admin surfaces | `super_admin` middleware (`EnsureSuperAdmin`) | Used by `ClientController`, `SuperAdminTenantController`, billing/plan/dashboard controllers |
| Controller-level, tenant isolation | `ensureSameClient()` in `UserController` | `abort(404)` unless caller has a non-null `client_id` equal to the target's |
| Model-level | None on `User` itself — `User` does **not** use `BelongsToClient` (unlike `Lead`) | Tenant scoping for users is enforced entirely at the controller layer, not via a global Eloquent scope |
| Mass-assignment protection | `UserRequest`/inline `$request->validate()` allowlists | `role` restricted to `admin`/`sales` only; `client_id` never accepted from the request body anywhere in `UserController` — confirmed by an existing test (`test_role_and_client_id_mass_assignment_protections_remain_intact`) |

## 5. Existing User Lifecycle Behavior

- Status is binary: `active` / `inactive` (DB-level `enum`, not a free string — a hard constraint, not just app-level validation).
- `inactive → active` (via `store()` with an explicit status, `update()`, or `toggleStatus()`) is gated by a **locked, transactional seat-limit check** against the target's tenant.
- `active → inactive` (via `update()` or `toggleStatus()`) **immediately revokes all of that user's Sanctum tokens** (`toggleStatus()` does this explicitly; a plain `update()` setting `status: inactive` does **not** currently revoke tokens — only `toggleStatus()` and a password change do). This is a real, verified asymmetry in the existing code, worth being aware of rather than assuming both paths behave identically.
- `destroy()` is a genuine **hard delete** — `$user->delete()`, no soft-delete column exists on `users`.
- Self-protection that already exists (tenant-scoped only): a `client_admin`/`admin` cannot change their own status (`toggleStatus`) or delete themselves (`destroy`) — both return `403` with a plain equality check (`$user->id === $request->user()->id`). **No equivalent self-protection exists for role changes** — `update()` lets an admin set their *own* role to `sales` via the same endpoint (not specifically tested either way, but nothing in the code prevents it for a `client_admin` acting on their own row within their tenant).

## 6. Existing Password/Reset Behavior

Covered above (§1/§2). Two independent, already-correct mechanisms exist:
1. **Self-service**: email-based token, hashed at rest, 60-minute expiry, wrong-guess-safe (doesn't burn the real token on a bad attempt), revokes all tokens on success.
2. **Admin-direct-set**: `client_admin`/`admin` supplies a new plaintext password directly via `UserController::update()`; hashed via `Hash::make()`; revokes the target's tokens.

Neither currently has a super_admin-facing equivalent.

## 7. Existing Role Assignment Behavior

- Only two roles are assignable via any existing API path: `admin` and `sales` (`UserRequest`'s `Rule::in(['admin', 'sales'])`).
- `client_admin` is **never** assignable via `UserController` — it is only ever created once, automatically, by `ClientController::store()` when a new tenant is created (Phase 5 audit already established this).
- **`sales_employee` and `sales_manager` are not assignable through any existing UI or API** — confirmed both by `UserRequest`'s validation allowlist and by a live query: **zero** users with either role exist in the real database today (62 total users: 36 `client_admin`, 25 `sales`, 1 `super_admin`, 0 `sales_employee`, 0 `sales_manager`). Per the brief's explicit instruction, this is flagged as a decision point for Phase 6 implementation, not resolved here — see §12.
- `super_admin` itself is never assignable via any existing API (consistent with it being a small, deliberately-curated set — currently exactly **one** real super_admin account on the platform).

## 8. Existing Audit Logging

**None, for user management specifically.** `ActivityLogger` is a mature, already-proven, already-reused piece of infrastructure (Phases 2–5), but `UserController` has never called it. Every other Super Admin surface built so far (`ClientController`, `SuperAdminPlanController`, `SuperAdminSubscriptionController`) does log via it. This is a genuine, pre-existing gap unrelated to Phase 6's own scope, but Phase 6's new Super-Admin-facing mutations should close it for themselves at minimum (per the brief's §10), without being asked to retrofit the older tenant-scoped `UserController` (out of scope — that would be "unrelated backend remediation").

## 9. Existing Tenant Relationships

- `User belongsTo Client` (nullable `client_id`, `nullOnDelete()` on the client side — confirmed in Phase 5's audit of the same FK).
- **`leads.created_by` is `cascadeOnDelete()` against `users`** — deleting a user permanently deletes every lead they ever created. **`leads.assigned_to` is `nullOnDelete()`** — deleting a user only unassigns (doesn't delete) leads assigned to them. This asymmetry already exists today for the tenant-scoped `UserController::destroy()` and is a real, live risk independent of Phase 6 — flagged here because Phase 6 must not casually extend platform-wide reach to this same hard-delete operation without addressing it (see §10/§12).
- `client_id` is a plain nullable FK column on `users` — no unique-per-tenant constraint of any kind exists on it.
- `users.email` has a **global** unique constraint (not scoped per tenant) — a Super-Admin-initiated cross-tenant user creation must respect this (an email already used in tenant A cannot be reused in tenant B), which is already how the existing tenant-scoped `store()` behaves too (nothing new here, just worth stating explicitly for the cross-tenant case).

## 10. Security Risks (Identified During This Audit)

1. **User deletion cascade risk** (verified via migration inspection, matching the exact class of risk already documented and deliberately avoided in Phase 5 for tenant deletion): a Super-Admin-facing "delete user" capability would permanently delete every lead that user ever created, platform-wide, for any tenant. **Recommendation:** do not expose user deletion in Phase 6; offer deactivation only, exactly as Phase 5 did for tenant deletion. The existing tenant-scoped `destroy()` is left untouched either way — this only concerns whether Phase 6 adds a *new*, cross-tenant version of it.
2. **Last-super_admin lockout risk**: with exactly one real super_admin account on the platform today, any Phase 6 capability that lets a super_admin deactivate or demote *another* super_admin (or, more subtly, their own account) needs an explicit guard — verified as a genuine, present-day risk, not a hypothetical.
3. **`update()`'s status-change token-revocation asymmetry** (§5): only `toggleStatus()` revokes tokens on deactivation; a status change via the generic `update()` path does not. Any new Super-Admin equivalent should decide deliberately which behavior to follow, rather than inconsistently mixing both.
4. **Dead frontend code presents a false signal**: the existing `/admin/users` page's `isSuperAdmin` branches could mislead a future reader into thinking cross-tenant user management partially exists. Recommend either removing that dead branch or clearly noting it will be superseded once Phase 6 ships.

## 11. Database Constraints

- `users.status`: DB-level `enum('active','inactive')` — no DB-level third state possible without a migration.
- `users.role`: plain `varchar(255)`, **no DB-level constraint** — any string could be stored if application-level validation were ever bypassed; the `Roles` canonical list is the only real guard.
- `users.email`: global unique index.
- `users.client_id`: FK to `clients.id`, `nullOnDelete()`, no unique-per-tenant constraint.
- No `last_login_at`, `last_seen_at`, or any activity-timestamp column exists on `users`. The `sessions` table exists (Laravel scaffolding) but is unused by this app's Sanctum-token-based API auth (already established fact from the Phase 5 report) — there is **no reliable "last login" data anywhere** in this application. Per the brief's explicit instruction, Phase 6 must not fabricate this; any "last activity" column in the UI must either be omitted or explicitly derived from a real, existing signal (e.g. most recent `ActivityLog` row for that user, or most recent lead/call/followup they touched — the same honest approach already used in the Phase 5 tenant detail page for "recent activity").
- **Platform scale (real data, queried live):** 62 total users (36 `client_admin`, 25 `sales`, 1 `super_admin`, 0 `sales_employee`, 0 `sales_manager`); 60 active / 2 inactive. This is a small dataset — a straightforward server-side-paginated query needs no new index to perform well; **no speculative index is justified by this audit**, consistent with the brief's explicit instruction not to add one without measured need.

## 12. Recommended Minimal Architecture (Recommendation — Not Yet Implemented)

**Backend:**
- New `SuperAdminUserController` under a new `admin/users` route prefix, inside the existing `Route::middleware('super_admin')->group(...)` block (same convention as `admin/tenants`/`admin/billing`).
- `index()`: paginated, server-side-filtered (search, role, tenant/`client_id`, status) list, eager-loading `client:id,name` to avoid N+1 — same style as `SuperAdminTenantController::index()`.
- `show($user)`: detail view — identity, role, tenant, status, timestamps, a recent-audit-activity panel (reusing `AuditLogController` with a new `user` resource type + `subject_id`), never returning `password`/tokens (already guaranteed by `User::$hidden`, but worth an explicit test).
- `store()`: reuse `UserRequest`'s validation shape but **do not reuse `UserController::store()` itself** (it's hard-tenant-scoped to the caller) — a new method that accepts an explicit, server-validated `client_id` (must `exists:clients,id`), still forbidding `client_id` to be inferred from anything else.
- `update()`: role/status/tenant changes, each independently guarded; reuse the existing seat-limit-on-reactivation pattern for any tenant this touches.
- Password: **recommend reusing the existing admin-direct-set pattern** (`Hash::make()` + `$user->tokens()->delete()`) already proven in `UserController::update()`, rather than building a second "trigger an email reset" flow — this keeps exactly one admin-initiated password-change mechanism in the codebase. (Open question for review, not decided here: should Super Admin instead only be able to *trigger* the existing self-service email reset, never see/set a password directly? Both reuse existing mechanisms; this is a genuine product decision, not a technical constraint.)
- **No delete endpoint** — deactivation only, per §10.
- Last-super_admin guard: block any mutation that would leave zero users with `role = 'super_admin' AND status = 'active'`.
- `AuditLogController::RESOURCE_TYPES` gains a `user => \App\Models\User::class` entry.
- Every mutation calls `ActivityLogger::log()`.

**Frontend:**
- New `/admin/users` experience for super_admin specifically (the existing tenant-scoped page at the same URL is client-admin-only and gated by a *different* `isAdmin` check — this needs a design decision: either the same URL branches by role, or Super Admin gets a distinct path such as `/admin/platform-users`. **Not decided here** — flagged as the first thing to resolve before writing any frontend code, since it affects the routing/`Layout.tsx` nav structure directly.)
- `/admin/users/[id]` detail page, following the exact static-export pattern already used by `/admin/clients/[id]` and `/admin/billing/subscriptions/[id]`.
- Reuse `Pagination`, `StatusBadge`, `ConfirmDialog`, `PasswordInput` as-is.

**Open decisions for explicit review before implementation (per the brief's own §4 instruction):**
1. Should `sales_employee`/`sales_manager` become assignable through Phase 6, given they exist in the role model but have zero real usage and no existing creation path?
2. Direct password set vs. trigger-existing-reset-email for Super Admin's password capability?
3. URL/routing strategy for the frontend Super Admin user page vs. the existing client-admin one at the same path.

---

**Audit complete. No implementation has occurred.**

Per the brief's explicit instruction, this workstream now **STOPS** pending review of this audit. Implementation begins only when explicitly authorized.
