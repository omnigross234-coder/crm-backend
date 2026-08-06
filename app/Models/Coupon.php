<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'expires_at',
        'usage_limit',
        'times_used',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'expires_at' => 'datetime',
    ];
}
