<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ActivityLogger
{
    public static function log(string $action, ?Model $subject = null, array $meta = []): void
    {
        $user = Auth::user();

        ActivityLog::create([
            'client_id'    => $user?->client_id,
            'user_id'      => $user?->id,
            'action'       => $action,
            'subject_type' => $subject ? get_class($subject) : null,
            'subject_id'   => $subject?->id,
            'meta'         => $meta,
            // Retain compatibility with the original activity_logs schema.
            'module'       => $subject ? class_basename($subject) : 'System',
            'record_id'    => $subject?->id,
            'description'  => $action,
            'ip_address'   => request()?->ip(),
        ]);
    }
}
