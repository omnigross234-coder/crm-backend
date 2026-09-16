<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
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

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function leads()
    {
        return $this->hasMany(Lead::class);
    }

    public function facebookPages()
    {
        return $this->hasMany(FacebookPage::class);
    }

    /**
     * Phase 5 (Tenant Management): the tenant's primary/first admin user,
     * for list/detail contact display. Uses oldestOfMany() (same pattern
     * already used elsewhere for a "most recent related row per parent"
     * relation) to generate a correct per-parent subquery instead of the
     * classic "limit() inside with() limits the whole query" mistake.
     */
    public function adminUser(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(\App\Models\User::class)
            ->whereIn('role', \App\Support\Roles::TENANT_ADMIN_ROLES)
            ->oldestOfMany('id');
    }
}
