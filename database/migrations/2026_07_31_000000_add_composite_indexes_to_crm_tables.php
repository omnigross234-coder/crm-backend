<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->index(['client_id', 'status'], 'leads_client_status_idx');
            $table->index(['client_id', 'assigned_to'], 'leads_client_assigned_idx');
        });

        Schema::table('followups', function (Blueprint $table) {
            $table->index(
                ['status', 'reminder_sent', 'next_followup_datetime'],
                'followups_status_reminder_next_idx'
            );
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->index(['user_id', 'called_at'], 'call_logs_user_called_idx');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_client_status_idx');
            $table->dropIndex('leads_client_assigned_idx');
        });

        Schema::table('followups', function (Blueprint $table) {
            $table->dropIndex('followups_status_reminder_next_idx');
        });

        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex('call_logs_user_called_idx');
        });
    }
};