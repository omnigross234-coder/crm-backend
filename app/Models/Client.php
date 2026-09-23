<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'status',
    'subscription_status',
    'trial_end_date',
    'subscription_end_date',
    'seat_limit',
    'suspended_at',];
    protected $casts = [
    'trial_end_date' => 'datetime',
    'subscription_end_date' => 'datetime',
    'suspended_at' => 'datetime',
];

    // public function users()
    // {
    //     return $this->hasMany(User::class);
    // }

    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(\App\Models\User::class);
    }
    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function facebookPages()
    {
        return $this->hasMany(FacebookPage::class);
    }
    public function subscriptions(): \Illuminate\Database\Eloquent\Relations\HasMany
{
    return $this->hasMany(\App\Models\Subscription::class);
}

public function activeSubscription(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\Subscription::class)
        ->whereIn('status', ['trial', 'active'])
        ->latestOfMany();
}

/**
 * Phase 5 (Tenant Management): the tenant's primary/first admin user,
 * for list/detail contact display. Uses oldestOfMany() (same pattern as
 * activeSubscription()'s latestOfMany() above) to generate a correct
 * per-parent subquery instead of the classic "limit() inside with()
 * limits the whole query" mistake.
 */
public function adminUser(): \Illuminate\Database\Eloquent\Relations\HasOne
{
    return $this->hasOne(\App\Models\User::class)
        ->whereIn('role', \App\Support\Roles::TENANT_ADMIN_ROLES)
        ->oldestOfMany('id');
}

public function activeUserCount(): int
{
    return $this->users()->where('status', 'active')->count();
}

public function hasAvailableSeat(): bool
{
    if ($this->seat_limit === null) {
        return true;
    }

    return $this->activeUserCount() < $this->seat_limit;
}

    
}