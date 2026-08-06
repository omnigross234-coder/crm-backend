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
}