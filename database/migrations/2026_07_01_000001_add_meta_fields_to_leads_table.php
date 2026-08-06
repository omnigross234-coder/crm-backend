<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (! Schema::hasColumn('leads', 'meta_leadgen_id')) {
                $table->string('meta_leadgen_id')->nullable()->unique()->after('created_by');
            }

            if (! Schema::hasColumn('leads', 'meta_form_id')) {
                $table->string('meta_form_id')->nullable()->after('meta_leadgen_id');
            }

            if (! Schema::hasColumn('leads', 'meta_page_id')) {
                $table->string('meta_page_id')->nullable()->after('meta_form_id');
            }

            if (! Schema::hasColumn('leads', 'meta_ad_id')) {
                $table->string('meta_ad_id')->nullable()->after('meta_page_id');
            }

            if (! Schema::hasColumn('leads', 'meta_platform')) {
                $table->string('meta_platform')->nullable()->after('meta_ad_id');
            }

            if (! Schema::hasColumn('leads', 'meta_raw_data')) {
                $table->json('meta_raw_data')->nullable()->after('meta_platform');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'meta_leadgen_id')) {
                $table->dropUnique(['meta_leadgen_id']);
            }

            $table->dropColumn([
                'meta_leadgen_id',
                'meta_form_id',
                'meta_page_id',
                'meta_ad_id',
                'meta_platform',
                'meta_raw_data',
            ]);
        });
    }
};
