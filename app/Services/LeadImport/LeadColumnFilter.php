<?php

namespace App\Services\LeadImport;

use Illuminate\Support\Facades\Schema;

/**
 * Keeps a data array down to only real `leads` columns before it reaches
 * forceFill() — the same guard LeadController::store/update has always
 * used, shared here so the import engine can't mass-assign a spreadsheet
 * column (e.g. one literally named "client_id" or "id") into anything it
 * shouldn't.
 */
class LeadColumnFilter
{
    public static function filter(array $data): array
    {
        return collect($data)
            ->filter(fn ($value, $key) => Schema::hasColumn('leads', $key))
            ->all();
    }
}
