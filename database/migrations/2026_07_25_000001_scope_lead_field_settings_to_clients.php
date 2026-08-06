<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('lead_field_settings', 'client_id')) {
            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasIndex('lead_field_settings', ['field_key'], 'unique')) {
            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->dropUnique(['field_key']);
            });
        }

        $templateFields = DB::table('lead_field_settings')
            ->whereNull('client_id')
            ->orderBy('sort_order')
            ->get();

        foreach (DB::table('clients')->pluck('id') as $clientId) {
            if (DB::table('lead_field_settings')->where('client_id', $clientId)->exists()) {
                continue;
            }

            foreach ($templateFields as $field) {
                $data = (array) $field;
                unset($data['id']);
                $data['client_id'] = $clientId;
                DB::table('lead_field_settings')->insert($data);
            }
        }

        if ($templateFields->isNotEmpty() && DB::table('clients')->exists()) {
            DB::table('lead_field_settings')->whereNull('client_id')->delete();
        }

        if (! Schema::hasIndex('lead_field_settings', ['client_id', 'field_key'], 'unique')) {
            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->unique(['client_id', 'field_key']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('lead_field_settings', ['client_id', 'field_key'], 'unique')) {
            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->dropUnique(['client_id', 'field_key']);
            });
        }

        if (Schema::hasColumn('lead_field_settings', 'client_id')) {
            $clientId = DB::table('lead_field_settings')->whereNotNull('client_id')->min('client_id');

            if ($clientId) {
                DB::table('lead_field_settings')
                    ->whereNotNull('client_id')
                    ->where('client_id', '!=', $clientId)
                    ->delete();
            }

            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->dropColumn('client_id');
            });
        }

        if (! Schema::hasIndex('lead_field_settings', ['field_key'], 'unique')) {
            Schema::table('lead_field_settings', function (Blueprint $table) {
                $table->unique('field_key');
            });
        }
    }
};
