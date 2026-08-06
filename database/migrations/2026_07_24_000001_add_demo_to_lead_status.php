<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE leads MODIFY status ENUM('new','interested','followup','demo','converted','closed','not_interested') NOT NULL DEFAULT 'new'");
    }

    public function down(): void
    {
        DB::table('leads')->where('status', 'demo')->update(['status' => 'interested']);
        DB::statement("ALTER TABLE leads MODIFY status ENUM('new','interested','followup','converted','closed','not_interested') NOT NULL DEFAULT 'new'");
    }
};
