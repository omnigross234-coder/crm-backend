<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallLog extends Model
{
    protected $fillable = [
        'user_id',
        'lead_id',
        'called_at',
        'duration_seconds',
        'is_connected',
        'status',
        'ended_at',
        'android_call_log_id',
        'sms_sent',
        'whatsapp_sent',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_connected' => 'boolean',
        'sms_sent' => 'boolean',
        'whatsapp_sent' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }
}
