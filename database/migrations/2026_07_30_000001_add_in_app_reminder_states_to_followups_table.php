<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            $table->timestamp('reminder_ready_at')->nullable()->after('reminder_sent');
            $table->timestamp('reminder_acknowledged_at')->nullable()->after('reminder_ready_at');
            $table->index(
                ['user_id', 'status', 'reminder_ready_at', 'next_followup_datetime'],
                'followups_in_app_reminder_lookup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('followups', function (Blueprint $table) {
            $table->dropIndex('followups_in_app_reminder_lookup_index');
            $table->dropColumn(['reminder_ready_at', 'reminder_acknowledged_at']);
        });
    }
};
