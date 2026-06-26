<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('followups', 'reminder_sent')) {
            Schema::table('followups', function (Blueprint $table) {
                $table->boolean('reminder_sent')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        // The earlier datetime migration may own this column.
    }
};
