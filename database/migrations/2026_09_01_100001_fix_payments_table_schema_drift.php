<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2A discovery (blocking dependency of BILL-F01..F05 and BILL-F06
 * regression tests): the `payments` table migration
 * (2026_08_04_100004_create_payments_table.php) does not match the actual
 * local `crmtestmain` schema. The live table already has `subscription_id`
 * and `coupon_id` columns and a nullable `invoice_id` (used by
 * BillingWebhookController/InvoiceService — a payment is created against a
 * subscription first, the invoice is generated and linked afterwards), none
 * of which are represented by any committed migration. Running
 * `migrate:fresh` against an empty database currently produces a `payments`
 * table that the application code cannot actually use.
 *
 * This migration brings the migration set back in sync with the real schema
 * without touching existing data. See PHASE2A-REMEDIATION-REPORT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'subscription_id')) {
                $table->foreignId('subscription_id')->nullable()->after('invoice_id')
                    ->constrained()->cascadeOnDelete();
            }
            if (! Schema::hasColumn('payments', 'coupon_id')) {
                $table->foreignId('coupon_id')->nullable()->after('subscription_id')
                    ->constrained()->nullOnDelete();
            }
        });

        Schema::table('payments', function (Blueprint $table) {
            $existing = collect(Schema::getIndexes('payments'))->pluck('name');

            if (! $existing->contains('idx_payments_status')) {
                $table->index('status', 'idx_payments_status');
            }
            if (! $existing->contains('idx_payments_gateway_ref')) {
                $table->index(['gateway', 'gateway_ref'], 'idx_payments_gateway_ref');
            }
        });

        // invoice_id must be nullable: a payment is created before its invoice
        // exists (InvoiceService links invoice_id back onto the payment once
        // generated). doctrine/dbal isn't installed, so Blueprint::change()
        // isn't available — do the nullability change with driver-appropriate
        // raw SQL instead. This only relaxes a constraint (NOT NULL -> NULL);
        // it cannot fail against existing data since every current row is by
        // definition non-null already.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE payments MODIFY invoice_id BIGINT UNSIGNED NULL');
        } elseif (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite has no ALTER COLUMN; the test DB is always empty/ephemeral
            // (RefreshDatabase, sqlite :memory:), so a table rebuild is safe.
            Schema::table('payments', function (Blueprint $table) {
                $table->unsignedBigInteger('invoice_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'coupon_id')) {
                $table->dropConstrainedForeignId('coupon_id');
            }
            if (Schema::hasColumn('payments', 'subscription_id')) {
                $table->dropConstrainedForeignId('subscription_id');
            }
            $table->dropIndex('idx_payments_status');
            $table->dropIndex('idx_payments_gateway_ref');
        });
    }
};
