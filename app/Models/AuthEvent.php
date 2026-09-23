<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Security Workstream C: one row per authentication-history event
 * (login_success, login_failed, logout, password_reset_requested,
 * password_reset_succeeded, password_reset_failed — see AuthEventLogger
 * for the authoritative taxonomy). Rows are immutable and created only
 * through AuthEventLogger::log(), never updated.
 */
class AuthEvent extends Model
{
    public $timestamps = true;

    const UPDATED_AT = null;

    protected $fillable = [
        'event',
        'user_id',
        'client_id',
        'login_identifier',
        'ip_address',
        'user_agent',
        'result',
        'failure_reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
