<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LeadFieldSetting extends Model
{
    protected $fillable = [
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
            'active' => 'boolean',
            'required' => 'boolean',
            'is_custom' => 'boolean',
            'sort_order' => 'integer',
        ];
    }
}
