<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A discovery (blocking dependency of BILL-F01/F04 testing): the live
 * `activity_logs.user_id` is already nullable, but the create-table
 * migration marks it NOT NULL. System/webhook-triggered activity — e.g.
 * SubscriptionService::activate() called from the unauthenticated Razorpay
 * webhook — legitimately has no acting user. `migrate:fresh` currently
 * produces an `activity_logs` table that rejects exactly this, which
 * silently breaks subscription activation (ActivityLogger::log() throws,
 * caught by the webhook's own RuntimeException handler, which logs it as
 * "skipped" and returns without activating). Same drift pattern as the
 * other Phase 2A schema-drift fixes in this batch. See
 * PHASE2A-REMEDIATION-REPORT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE activity_logs MODIFY user_id BIGINT UNSIGNED NULL');

            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE activity_logs MODIFY user_id BIGINT UNSIGNED NOT NULL');

            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
