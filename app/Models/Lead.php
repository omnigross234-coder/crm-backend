<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Lead extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'phone', 'email', 'company', 'address',
        'city', 'state', 'country', 'pin_code', 'referral_name',
        'industry_type', 'business_type', 'product_service_interested_in',
        'budget', 'documents', 'annual_turnover', 'gst_number', 'requirement',
        'source', 'status', 'priority', 'call_notes',
        'assigned_to', 'remarks', 'created_by',
    ];

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function followups(): HasMany
    {
        return $this->hasMany(Followup::class);
    }
}
