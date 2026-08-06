<?php

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use Illuminate\Database\Eloquent\Model;

class AppSetting extends Model
{
    use BelongsToClient;

    protected $fillable = ['client_id', 'key', 'value'];
}