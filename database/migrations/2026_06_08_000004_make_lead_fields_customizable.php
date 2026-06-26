<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_field_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('lead_field_settings', 'field_type')) {
                $table->string('field_type', 30)->default('text')->after('label');
            }
            if (! Schema::hasColumn('lead_field_settings', 'is_custom')) {
                $table->boolean('is_custom')->default(false)->after('required');
            }
        });

        DB::table('lead_field_settings')
            ->whereIn('field_key', ['address', 'documents', 'requirement'])
            ->update(['field_type' => 'textarea']);
    }

    public function down(): void
    {
        Schema::table('lead_field_settings', function (Blueprint $table) {
            if (Schema::hasColumn('lead_field_settings', 'field_type')) {
                $table->dropColumn('field_type');
            }
            if (Schema::hasColumn('lead_field_settings', 'is_custom')) {
                $table->dropColumn('is_custom');
            }
        });
    }
};
