<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $defaultClientId = DB::table('clients')->where('slug', 'default-client')->value('id');

        if (! $defaultClientId) {
            $defaultClientId = DB::table('clients')->insertGetId([
                'name' => 'Default Client',
                'slug' => 'default-client',
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('users')
            ->whereNull('client_id')
            ->where('role', '!=', 'super_admin')
            ->update(['client_id' => $defaultClientId]);

        if (! Schema::hasColumn('leads', 'client_id')) {
            Schema::table('leads', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('id');
            });
        }

        DB::table('leads')
            ->whereNull('client_id')
            ->orWhereNotIn('client_id', DB::table('clients')->select('id'))
            ->update(['client_id' => $defaultClientId]);

        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
