<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class LeadFieldSetting extends Model
{
    use BelongsToClient;

    protected $fillable = [
        'client_id',
        'field_key',
        'label',
        'field_type',
        'active',
        'required',
        'is_custom',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'client_id' => 'integer',
            'active' => 'boolean',
            'required' => 'boolean',
            'is_custom' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
