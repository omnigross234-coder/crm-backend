<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            // e.g. 'razorpay', 'cashfree', 'manual' — kept as a free string so Phase 3
            // can add gateways without a migration.
            $table->string('gateway')->nullable();
            $table->string('gateway_ref')->nullable();
            $table->decimal('amount', 15, 2);
            $table->enum('status', ['pending', 'success', 'failed'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('gateway_ref');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
