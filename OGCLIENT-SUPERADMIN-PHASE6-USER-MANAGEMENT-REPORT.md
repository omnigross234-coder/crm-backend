# OGCLIENT — Super Admin Phase 6: Advanced User Management Control Center

**Date:** 2026-09-16
**Scope:** New Super Admin cross-tenant User Management Control Center (backend + frontend). Entirely additive — the existing tenant-scoped `UserController` and its `/admin/users` frontend page are untouched and continue to serve `client_admin`/`admin` exactly as before.

---

## 1. Audit Summary

Full findings in `OGCLIENT-SUPERADMIN-PHASE6-AUDIT-REPORT.md` (produced and reviewed before any implementation, per the phase's own two-step structure). Headline facts, reconfirmed here:

- **Zero pre-existing Super Admin user-management capability.** `UserController`'s routes are gated by `role:admin,client_admin` (super_admin explicitly excluded → 403), and every method is hard-tenant-scoped via a private `ensureSameClient()` check that requires the caller to *have* a `client_id` at all.
- The existing `/admin/users` frontend page's `isSuperAdmin`-branching code was dead: its own guard (`isAdmin` in `lib/auth.tsx`) excludes `super_admin`, so a super_admin was redirected away before that code path could ever run.
- Only `admin` and `sales` were assignable via any existing API. `client_admin` is only ever created once, automatically, at tenant creation.
- `leads.created_by` is `cascadeOnDelete()` against `users` — the same class of risk Phase 5 already avoided for tenant deletion.
- `UserController` never calls `ActivityLogger` — tenant-scoped user management has always been unaudited.
- Real data: 62 users platform-wide (36 `client_admin`, 25 `sales`, 1 `super_admin`, 0 `sales_employee`, 0 `sales_manager`) — **exactly one real Super Admin account**, making last-Super-Admin protection a live, present-day concern, not a hypothetical.

## 2. Architecture

New `SuperAdminUserController` under a new `admin/users` route prefix, inside the **existing** `Route::middleware('super_admin')->group(...)` block that already houses `SuperAdminTenantController`, `SuperAdminPlanController`, etc. — the same convention established in Phases 3–5. No new middleware, no new role system, no new audit system.

| Layer | Reused as-is | New |
|---|---|---|
| Authorization | `super_admin` route middleware, `App\Support\Roles` | — |
| Password | `PasswordResetController`'s token/email mechanism | `issueResetToken(User $user)` — a pure extraction of `forgotPassword()`'s existing per-user logic, so a second caller can reuse it |
| Audit | `ActivityLogger::log()`, `AuditLogController` | `user => \App\Models\User::class` added to `AuditLogController::RESOURCE_TYPES` |
| Tenant seat limits | `Client::hasAvailableSeat()` / `activeUserCount()` | — |
| Frontend primitives | `Pagination`, `StatusBadge`, `ConfirmDialog` | — |
| Frontend pattern | `/admin/clients` + `/admin/clients/[id]` (Phase 5) as direct structural template | `/admin/platform/users` + `/admin/platform/users/[id]` |

## 3. Product Decisions Applied

1. **Roles**: all six canonical roles preserved exactly; no renaming, no new semantics.
2. **Password**: Super Admin never types or sets another user's password anywhere in this feature. New users get a random, immediately-hashed, never-returned password and an automatic password-setup email via the existing reset mechanism. A "Send Password Reset" action is available on demand for any existing user, using the same mechanism.
3. **Frontend route**: `/admin/users` (existing, client-admin) is completely untouched — confirmed by a passing regression test and a real browser check (scenario 23). The new Super Admin surface lives at `/admin/platform/users`, linked only from the super_admin sidebar section.

## 4. Role Behavior — Investigated, Not Invented

Per the explicit instruction to investigate `sales_employee`/`sales_manager` before exposing either:

- **`sales_employee`: fully valid, exposed.** Every authorization path in the codebase (`LeadController::scopeLeadsForUser()`, `CallLogController`, `FollowupController`, `BelongsToClient`, every `role:` route middleware list) treats it identically to `sales` — none of them special-case `'sales'` as a literal string in a way that would exclude it. Confirmed by direct inspection of every such site.
- **`sales_manager`: NOT exposed — a genuine, verified gap, not an invented restriction.** `LeadController::assign()` (`POST /leads/{id}/assign`, line ~434) hard-codes `in_array($salesUser->role, ['sales', 'sales_employee'], true)` — omitting `sales_manager`. A `sales_manager` user created today could not be assigned new leads through the app's normal admin workflow. Fixing that array is a one-line change, but it lives in an unrelated controller and touches lead-assignment authorization — explicitly out of this phase's scope ("do not modify their permission semantics merely to enable them"). `sales_manager` is shown as a visibly disabled option in both the create and edit role selects, with inline text explaining why, rather than silently omitted.
- **`client_admin`**: exposed. Functionally identical to `admin` everywhere (`Roles::TENANT_ADMIN_ROLES`) — no gap found. Assigning it via Super Admin does not change the existing tenant-admin model; a tenant can already have been observed with multiple `client_admin`/`admin` rows before this phase.
- **`super_admin`**: exposed as a creatable/assignable role, with a hard-enforced invariant (`client_id` must be `null`) matching every other place in the codebase that treats `client_id === null` as synonymous with "is super_admin."

## 5. Authorization Matrix

| Actor | List/view any user | Create user | Edit profile/role/tenant | Activate/deactivate | Send password reset |
|---|---|---|---|---|---|
| `super_admin` | ✅ platform-wide | ✅ any tenant | ✅ any user (self-protection applies) | ✅ any user (self + last-admin protection applies) | ✅ any user |
| `client_admin` / `admin` | ❌ (403, unchanged tenant-scoped `/users` still works for their own tenant) | ❌ | ❌ | ❌ | ❌ |
| `sales` / `sales_employee` / `sales_manager` | ❌ | ❌ | ❌ | ❌ | ❌ |
| Unauthenticated | ❌ (401) | ❌ | ❌ | ❌ | ❌ |

Every row above is backed by a passing automated test (§9), not just this table.

## 6. Self-Protection Rules

Implemented as two independent, server-side checks (never dependent on frontend button disabling):

1. **`guardSelfProtection()`** — unconditional, applies to the ACTOR acting on their own account:
   - Cannot deactivate self.
   - Cannot change own role away from `super_admin`.
   - Cannot change own tenant assignment.
2. **`guardLastActiveSuperAdmin()`** — count-based, applies to any target: blocks a role change away from `super_admin` or a deactivation if the target is currently the only user with `role=super_admin AND status=active`.

**Important, verified architectural finding, documented rather than glossed over:** given the existing middleware stack (`EnsureSuperAdmin` checks the actor's *role*; `EnsureAccountIsActive` checks the actor's own *status* and rejects inactive accounts outright, even super_admin ones, before the request ever reaches this controller), **any authenticated caller of this controller is, by definition, themselves a currently-active super_admin.** This means if the target of a mutation is a *different* user, the count of active super_admins is always ≥ 2 at the moment of the check (the actor + the target) — so the count-based guard's zero-count branch can only ever actually be reached via a self-action, which rule 1 already blocks unconditionally and first. The count-based guard remains in the code as defense-in-depth (e.g. against a future bulk-action endpoint or a different actor-verification path), and its logic is verified correct via a direct unit-style test (invoking the method via reflection, bypassing the middleware it can never get past today) — but it is not independently exercisable through the live HTTP API, and the report states this plainly rather than presenting a misleading end-to-end test as if it covered a scenario the API cannot actually produce.

## 7. Audit Logging

Every mutation calls `ActivityLogger::log()`:

| Action | When |
|---|---|
| `user.created` | New user created |
| `user.password_reset_initiated` | On creation (automatic) and on-demand via "Send Password Reset" (`meta.trigger` distinguishes the two, no token/secret ever included) |
| `user.updated` | Profile/role/tenant change, with a `before`/`after` diff in `meta` (only logged if something actually changed) |
| `user.active` / `user.inactive` | Status toggle |

Audit records were verified (by test, not assumption) to never contain a password, hash, or token string.

## 8. API Changes

New routes, all under the existing `super_admin` middleware group:

```
GET    /api/admin/users
POST   /api/admin/users
GET    /api/admin/users/{user}
PUT    /api/admin/users/{user}
PATCH  /api/admin/users/{user}/status
POST   /api/admin/users/{user}/send-password-reset
```

No existing route, request contract, or response shape was changed. `PasswordResetController::forgotPassword()`'s public behavior is byte-for-byte identical (the extraction only moved code, it didn't change what it does).

## 9. Database Changes

**None.** No migration was added or needed. `users.role` remains a plain `varchar` (no DB-level enum change); `users.status` remains the existing `enum('active','inactive')`. Query performance was not a concern at this scale (62 users platform-wide) and no index was added, consistent with the audit's explicit finding that none was justified.

## 10. Frontend Implementation

- **`/admin/platform/users`**: server-side paginated (25/page, hard-capped at 100/page by the backend), search (debounced, name/email), role/tenant/status filters with a clear-filters affordance, skeleton loading, empty state, collapsible create form, per-row Deactivate/Activate action with confirmation.
- **`/admin/platform/users/[id]`**: identity/role/tenant/created-date cards, an editable profile/role/tenant form (self-protection reflected in the UI — disabled controls with an explanatory tooltip for the caller's own account), activate/deactivate and send-password-reset actions (each behind a `ConfirmDialog`, matching the "no native `alert()`/`confirm()`" requirement), and a Security & Activity panel reusing the Phase 2 audit-log reader via the newly-registered `user` resource type. Follows the exact static-export `[id]` pattern already used by `/admin/clients/[id]` and `/admin/billing/subscriptions/[id]` (placeholder `generateStaticParams`, `usePathname()`-parsed real ID, matching `.htaccess` rewrite rules).
- **Navigation**: a new "Users" entry added to the super_admin-only sidebar section (`superAdminOnlyItems` in `components/Layout.tsx`) — confirmed absent from the client-admin sidebar section, so `client_admin`/`admin` never see it.
- **Types**: `PlatformRole`, `PlatformUserListRow`, `PlatformUserDetail` added to `lib/api.ts`, independent of the existing `User` type (which the untouched `/admin/users` page continues to use).

**A real frontend bug was found and fixed during browser QA** (not left in): the detail page's loading conditional (`{loading ? <spinner/> : ...}`) blanked the ENTIRE page content back to a spinner on every refetch after a successful edit or status change — including the just-shown success message and all already-loaded data — because it didn't distinguish "first load, no data yet" from "brief refetch after a mutation, data already present." Fixed to `{loading && !d ? <spinner/> : ...}`, so an existing view stays visible (still updating) during a background refresh instead of flashing to a blank spinner. Verified visually before and after via real browser screenshots.

## 11. Security Tests

New file: `tests/Feature/SuperAdminUserManagementTest.php` (38 tests). Covers, per the brief's own checklist:

- **Authorization**: super_admin cross-tenant list/view/create; `client_admin`, `admin`, `sales`, `sales_employee`, `sales_manager` all denied (403); unauthenticated denied (401).
- **Role assignment**: `sales_employee` succeeds; `sales_manager` rejected (422, no record created) with the exact gap cited in code; `client_admin` and `super_admin` succeed; a `super_admin` role with a non-null `client_id` is rejected; a tenant-scoped role with no `client_id` is rejected.
- **Tenant assignment**: moving a user to a different tenant, blocked when the target tenant is at its seat limit, rejected for a nonexistent tenant ID, and confirmed that the existing tenant-scoped endpoint (which doesn't even accept `client_id`) cannot be used by a `client_admin` to escape their tenant.
- **Self-protection**: self-demotion rejected, self-deactivation rejected, self-tenant-reassignment rejected — each verified against the database, not just the response code.
- **Last active Super Admin**: verified via direct invocation of the guard method (see §6 for why this, rather than a live HTTP scenario, is the correct and honest way to test this specific branch), plus a control case proving it does *not* block when another active super_admin exists.
- **ID/value tampering**: nonexistent user ID → 404 (not 500); invalid role string → 422; invalid status string → 422.
- **Sensitive data**: password hash never appears in list or detail responses; password-reset initiation never returns a token; a newly-created user's password is confirmed (via `Hash::check`) to not be empty, not `"password"`, not `"sales"`, and not derivable from the email.
- **Filtering/search/pagination**: search by name/email, role filter, tenant filter, bounded pagination (`per_page` hard-capped, rejecting an oversized value with 422).
- **Regression**: the existing tenant-scoped `/users` list and update endpoints, called exactly as `client_admin` always has, still work unchanged.

**A real test-design flaw was caught and fixed, not hidden**: an early version of the last-Super-Admin test used an actor who had already been demoted to `sales` in an earlier step of the same test, so its `assertForbidden()` passed for the wrong reason (the `EnsureSuperAdmin` route middleware rejecting the actor outright, never reaching the guard logic being tested). Verified this by deliberately disabling the guard and confirming the flawed test still passed — a false positive. Replaced with the honest, reflection-based test described in §6, and re-verified that deliberately disabling the guard now correctly fails that test.

## 12. Performance

- Server-side pagination throughout (25/page default, 100/page hard cap, matching `SuperAdminTenantController`'s existing convention).
- `index()` eager-loads `client:id,name,status` — no N+1 across a page of results.
- No unbounded query anywhere; no full user table ever sent to the browser.
- No index added — 62 real users platform-wide needs none, and none was speculatively added.

## 13. Browser QA

Performed for real via headless Chrome + the Chrome DevTools Protocol — **28/28 scenarios passing** on the final run, covering all 23 scenarios named in the brief plus additional targeted checks:

1–2. List loads with real, live data (66 real users visible at the time of the run). 3. Search. 4. Role filter. 5. Tenant filter. 6. Status filter. 7. Default sort renders without error. 8. Pagination control present. 9. User detail loads with all sections. 10. Create user (with the automatic password-setup email flow). 11. Edit user. 12. Role change (genuinely exercised: sales → admin, verified via the audit trail). 13. Tenant change (genuinely exercised, not just checked for UI presence — moved a real user between two real tenants). 14. Deactivate/activate. 15. Confirmation dialogs (both for status change and password reset). 16. Validation failure (missing tenant for a tenant-scoped role). 17. Server error / not-found state for a nonexistent user ID. 18. Mobile layout (390px, no horizontal overflow). 19. Dark mode (default theme, screenshotted). 20. Unauthorized access (client_admin redirected to `/dashboard`). 21. ID/tenant tampering (direct API call as client_admin against `/admin/users` → 403). 22. Sensitive fields never exposed (verified against a live API response, not just the UI). 23. Existing `/admin/users` client-admin page confirmed still fully functional, zero console errors.

Two genuine issues were found and fixed during this QA pass, not merely worked around in the test script:
- The frontend loading-state bug described in §10.
- Several test-script timing issues (insufficient wait after debounced searches and multi-round-trip mutations under the accumulated load of a long headless-browser session) — the same class of dev-server-performance artifact already documented from Phases 3–5, diagnosed with a dedicated isolated repro before concluding it wasn't an app bug, and fixed by waiting for an actual state change (polling) rather than a fixed delay.

All disposable QA accounts and test-created user records were deleted after verification; the platform's real user count was confirmed back at its original baseline (62) before finishing.

## 14. Limitations

- **`sales_manager` is not assignable** — a verified, pre-existing gap in `LeadController::assign()`, not fixed here (out of scope). Documented in code, in this report, and visibly in the UI (disabled option with explanatory text).
- **No user deletion** — by design, per the brief; only activation/deactivation is exposed, for the same `leads.created_by` cascade-delete reason already established in Phase 5 for tenants.
- **The existing tenant-scoped `UserController` remains unaudited** — this phase added audit logging only to its own new endpoints, not retroactively to the pre-existing tenant-scoped ones (that would be unrelated remediation).
- **No "last login" data** — there is none anywhere in this application (confirmed in the audit); the user detail page's activity panel shows real audit-log events instead of fabricating a login timestamp.
- **The count-based last-active-Super-Admin guard is not independently reachable via the live API today** — see §6. It is correct, tested, and present as defense-in-depth, not dead code removed, but its practical enforcement today comes entirely from the simpler, unconditional self-protection rule.

## 15. Files Changed

**Backend:**
- `app/Http/Controllers/Api/SuperAdminUserController.php` — new
- `tests/Feature/SuperAdminUserManagementTest.php` — new, 38 tests
- `app/Http/Controllers/Api/PasswordResetController.php` — modified (extracted `issueResetToken()`; `forgotPassword()`'s own behavior unchanged)
- `app/Http/Controllers/Api/AuditLogController.php` — modified (added `user` resource type)
- `routes/api.php` — modified (added `admin/users` route group + import)

**Frontend:**
- `app/admin/platform/users/page.tsx` — new
- `app/admin/platform/users/[id]/page.tsx` — new
- `app/admin/platform/users/[id]/PlatformUserDetailClient.tsx` — new
- `lib/api.ts` — modified (added `PlatformRole`, `PlatformUserListRow`, `PlatformUserDetail`)
- `components/Layout.tsx` — modified (added the new nav entry to `superAdminOnlyItems` only)
- `public/.htaccess` — modified (added the `[id]` placeholder rewrite rules)

**Explicitly not modified**, confirmed by regression test and real browser check: `app/Http/Controllers/Api/UserController.php`, `app/Http/Requests/UserRequest.php`, `routes/api.php`'s existing `users/*` block, `app/admin/users/page.tsx`.

## 16. Test Results

| Run | Tests | Failures |
|---|---|---|
| Targeted (`SuperAdminUserManagementTest.php`) | 38 | 0 |
| Full suite, main process (`LeadImportTest.php` excluded — documented pre-existing memory-limit isolation need) | 428 (417 passed + 11 skipped) | 0 |
| `LeadImportTest.php` in isolation | 33 | 0 |
| **Total** | **461** | **0** |

Up from the 423-test baseline by exactly 38 — this file's test methods. No other test count changed. Frontend: `tsc --noEmit` clean, `eslint` clean, `next build` clean (both new routes built successfully alongside the untouched `/admin/users`).

## 17. Remaining Risks

- The `sales_manager` lead-assignment gap (§4/§14) should be fixed in its own dedicated, reviewed pass before that role is ever exposed anywhere.
- If `SUBSCRIPTION_ENFORCEMENT` (the dormant flag noted in the Phase 5/6 audits) is ever enabled, the legacy `clients.subscription_status` columns would need reconciling with the real `subscriptions` table — unrelated to this phase, but still an open item from prior audits.
- The existing tenant-scoped `UserController` remains unaudited (§14) — a natural candidate for a future, separately-scoped pass, not assumed to be "fixed" by this phase.

---

**HARD STOP.** Phase 6 (Advanced Super Admin User Management) complete. Not proceeding to Security Center, Impersonation, Refunds, Command Palette, Backup redesign, or any other workstream. Awaiting further instruction.
