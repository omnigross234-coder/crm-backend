<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->boolean('sms_sent')->default(false)->after('is_connected');
            $table->boolean('whatsapp_sent')->default(false)->after('sms_sent');
        });
    }

    public function down(): void
    {
        Schema::table('call_logs', function (Blueprint $table) {
            $table->dropColumn(['sms_sent', 'whatsapp_sent']);
        });
    }
};