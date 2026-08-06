<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('call_logs', 'status')) {
                $table->string('status', 32)->default('completed')->after('is_connected');
            }
            if (! Schema::hasColumn('call_logs', 'ended_at')) {
                $table->timestamp('ended_at')->nullable()->after('called_at');
            }
            if (! Schema::hasColumn('call_logs', 'android_call_log_id')) {
                $table->string('android_call_log_id', 191)->nullable()->after('ended_at');
            }
            if (! Schema::hasIndex('call_logs', ['user_id', 'status'])) {
                $table->index(['user_id', 'status']);
            }
        });

        DB::table('call_logs')
            ->where('is_connected', false)
            ->update(['status' => 'not_connected']);
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropColumn(['status', 'ended_at', 'android_call_log_id']);
        });
    }
};
