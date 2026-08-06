<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('call_logs', 'direction')) {
                $table->string('direction', 16)->default('outgoing')->after('lead_id');
            }
            if (! Schema::hasIndex('call_logs', ['user_id', 'direction'])) {
                $table->index(['user_id', 'direction']);
            }
            if (! Schema::hasIndex('call_logs', 'call_logs_user_android_id_unique')) {
                $table->unique(['user_id', 'android_call_log_id'], 'call_logs_user_android_id_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropUnique('call_logs_user_android_id_unique');
            $table->dropIndex(['user_id', 'direction']);
            $table->dropColumn('direction');
        });
    }
};
