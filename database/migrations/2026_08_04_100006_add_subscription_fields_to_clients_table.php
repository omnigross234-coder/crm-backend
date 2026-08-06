<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // Denormalized copy of the subscription's status, so middleware
            // (EnsureAccountIsActive) can check it without joining subscriptions.
            $table->string('subscription_status')->default('trial')->after('status');
            $table->timestamp('trial_end_date')->nullable()->after('subscription_status');
            $table->timestamp('subscription_end_date')->nullable()->after('trial_end_date');
            $table->unsignedInteger('seat_limit')->nullable()->after('subscription_end_date');
            $table->timestamp('suspended_at')->nullable()->after('seat_limit');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'subscription_status',
                'trial_end_date',
                'subscription_end_date',
                'seat_limit',
                'suspended_at',
            ]);
        });
    }
};
