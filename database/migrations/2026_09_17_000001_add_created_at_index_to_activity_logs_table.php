<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Super Admin Security Center, Phase 1 — Database/Performance audit.
 *
 * `activity_logs` had no index on `created_at`. Measured live via
 * EXPLAIN against the real local database (2,644 rows at the time):
 * `SELECT * FROM activity_logs WHERE created_at BETWEEN ? AND ?
 * ORDER BY created_at DESC LIMIT 25` produced `type: ALL` (a full
 * table scan), `key: NULL`. This already affects the pre-existing
 * `GET /api/audit-logs` endpoint's own `from`/`to` filters today, not
 * only the new Security Center overview endpoint added alongside this
 * migration — both share the identical unindexed query pattern.
 *
 * Purely additive: no column change, no data change, fully reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
