<?php
// database/migrations/2026_05_19_000001_update_followups_add_datetime.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            // Add datetime column right after existing next_followup_date
            $table->dateTime('next_followup_datetime')->nullable()->after('next_followup_date');
            // Track if reminder notification was sent
            $table->boolean('reminder_sent')->default(false)->after('next_followup_datetime');
        });
    }

    public function down(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            $table->dropColumn(['next_followup_datetime', 'reminder_sent']);
        });
    }
};