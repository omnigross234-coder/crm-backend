<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Followup extends Model
{
    use HasFactory;

    protected $fillable = [
        'lead_id',
        'user_id',
        'note',
        'next_followup_date',
        'next_followup_datetime',  // ← new
        'reminder_sent',           // ← new
        'reminder_ready_at',
        'reminder_acknowledged_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'next_followup_date'     => 'date',
            'next_followup_datetime' => 'datetime',  // ← new
            'reminder_sent'          => 'boolean',   // ← new
            'reminder_ready_at' => 'datetime',
            'reminder_acknowledged_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
