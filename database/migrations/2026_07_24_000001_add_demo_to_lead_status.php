<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM / MODIFY are MySQL-only. On other drivers (SQLite, used by the
        // test suite) `status` is a plain string column, so widening the set of
        // allowed values needs no schema change at all. Guarding here keeps the
        // MySQL behaviour byte-for-byte identical while letting the migration
        // run under `RefreshDatabase`.
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE leads MODIFY status ENUM('new','interested','followup','demo','converted','closed','not_interested') NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::table('leads')->where('status', 'demo')->update(['status' => 'interested']);

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE leads MODIFY status ENUM('new','interested','followup','converted','closed','not_interested') NOT NULL DEFAULT 'new'");
    }
};
