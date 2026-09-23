<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security Workstream C: a narrow, append-only authentication history,
 * separate from `activity_logs` (which remains the source of truth for
 * administrative/business actions — see AuthEventLogger's own doc comment
 * for why the two are never merged). Feeds the future Super Admin
 * Security Center with factual login/logout/password-reset history.
 *
 * Every column here is either always safely knowable (event, result,
 * ip_address, timestamp) or explicitly nullable for the many auth events
 * that legitimately occur before any user/tenant context exists (a failed
 * login against a nonexistent email, a password-reset request for an
 * unregistered address) — nullable, never a fabricated value, per the
 * workstream's explicit privacy rule.
 *
 * No retention/pruning is implemented: this project has no existing
 * retention policy for any audit-style table (activity_logs has none
 * either — confirmed by inspection, no prune command exists anywhere in
 * app/Console/Commands/), and the workstream brief explicitly says not to
 * invent one. Documented as a follow-up in the workstream report instead
 * of silently deleting rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_events', function (Blueprint $table) {
            $table->id();

            // Small, closed, stable set — see AuthEventLogger for the
            // exact list. Long enough for the longest current value
            // ('password_reset_requested') with headroom, short enough to
            // stay a real index rather than an unbounded text column.
            $table->string('event', 40);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            // The submitted email, normalized (lower-cased/trimmed) —
            // never the password, never the reset token. Populated even
            // when user_id is NULL (a failed/attempted login or reset
            // request against an email with no matching account), which
            // is exactly the case where the Security Center most needs a
            // filterable identifier and there's no user_id to join on.
            $table->string('login_identifier')->nullable();

            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->string('result', 10);
            $table->string('failure_reason', 60)->nullable();

            // Immutable audit rows — created_at only, no updated_at.
            $table->timestamp('created_at')->useCurrent();

            $table->index('user_id');
            $table->index('client_id');
            $table->index('event');
            $table->index('result');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_events');
    }
};
