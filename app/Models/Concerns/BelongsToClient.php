<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

trait BelongsToClient
{
    protected static function bootBelongsToClient(): void
    {
        // Auto-scope every query to the logged-in user's client,
        // unless the user is a super_admin (who sees everything).
        static::addGlobalScope('client', function (Builder $builder) {
            $user = Auth::user();
            if ($user && $user->role !== 'super_admin') {
                $builder->where($builder->getModel()->getTable() . '.client_id', $user->client_id);
            }
        });

        // Auto-fill client_id on create.
        static::creating(function ($model) {
            if (empty($model->client_id)) {
                $user = Auth::user();
                if ($user && $user->role !== 'super_admin') {
                    $model->client_id = $user->client_id;
                }
            }
        });
    }
}