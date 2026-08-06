<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->after('id')
                ->constrained('clients')->cascadeOnDelete();
            $table->dropUnique(['key']);
        });

        // one settings row per client per key
        Schema::table('app_settings', function (Blueprint $table) {
            $table->unique(['client_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::table('app_settings', function (Blueprint $table) {
            $table->dropUnique(['client_id', 'key']);
            $table->dropConstrainedForeignId('client_id');
            $table->unique('key');
        });
    }
};
