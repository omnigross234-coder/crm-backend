<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('activity_logs', 'client_id')) {
                $table->foreignId('client_id')->nullable()->after('id')
                    ->constrained('clients')->nullOnDelete();
            }
            if (! Schema::hasColumn('activity_logs', 'subject_type')) {
                $table->string('subject_type')->nullable()->after('action');
            }
            if (! Schema::hasColumn('activity_logs', 'subject_id')) {
                $table->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            }
            if (! Schema::hasColumn('activity_logs', 'meta')) {
                $table->json('meta')->nullable()->after('subject_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
            }
            $table->dropColumn(array_values(array_filter(
                ['subject_type', 'subject_id', 'meta'],
                fn (string $column) => Schema::hasColumn('activity_logs', $column)
            )));
        });
    }
};
