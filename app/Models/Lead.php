<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;



class Lead extends Model
{
    use BelongsToClient, HasFactory;

    protected $fillable = [
        'client_id', 'name', 'phone', 'email', 'company', 'address',
        'city', 'state', 'country', 'pin_code', 'referral_name',
        'industry_type', 'business_type', 'product_service_interested_in',
        'budget', 'documents', 'annual_turnover', 'gst_number', 'requirement',
        'source', 'status', 'priority', 'call_notes',
        'assigned_to', 'remarks', 'created_by',
        'meta_leadgen_id', 'meta_form_id', 'meta_page_id', 'meta_ad_id',
        'meta_platform', 'meta_raw_data',
    ];
    

    protected $casts = [
        'meta_raw_data' => 'array',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

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

    public function callLogs(): HasMany
    {
        return $this->hasMany(CallLog::class);
    }
}
