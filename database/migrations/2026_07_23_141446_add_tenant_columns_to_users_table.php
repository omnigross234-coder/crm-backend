<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // nullable because super_admin has no client
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained('clients')->nullOnDelete();

            // super_admin | client_admin | sales_manager | sales_employee
            $table->string('role')->default('sales_employee')->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};