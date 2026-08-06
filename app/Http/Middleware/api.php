<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CallLogController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\FollowupController;
use App\Http\Controllers\Api\FollowupReminderController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadFieldSettingController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;




// Public
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

});
Route::post('/test-lead', [LeadController::class, 'storeTestLead']);
// metaAdds test routes

Route::get('/meta/webhook', [LeadController::class, 'verifyWebhook']);
Route::post('/meta/webhook', [LeadController::class, 'receiveWebhook']);

Route::get('/run-reminders', function (Request $request) {
    $expectedKey = config('services.cron.reminder_key');
    $providedKey = (string) $request->query('key', '');

    if (! $expectedKey || ! hash_equals($expectedKey, $providedKey)) {
        return response()->json([
            'success' => false,
            'message' => 'Unauthorized.',
        ], 403);
    }

    Artisan::call('followups:send-reminders');

    return response()->json([
        'success' => true,
        'message' => 'Reminder command executed.',
        'output' => trim(Artisan::output()),
    ]);
});

Route::get('/run-backup', [BackupController::class, 'runFromCron'])
    ->middleware('throttle:3,1');

// Protected
// Route::middleware('auth:sanctum')->group(function () {

Route::middleware([
    'auth:sanctum',
    'account.active',
])->group(function () {
    Route::prefix('auth')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });

    Route::get('dashboard/stats', [DashboardController::class, 'stats']);

    Route::middleware('super_admin')->group(function () {
        Route::apiResource('clients', ClientController::class);
        Route::post('backup/run', [BackupController::class, 'run']);
    });

    Route::get('backup/list', [BackupController::class, 'list'])
        ->middleware('role:super_admin,client_admin,admin');

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::patch('notifications/read-all', [NotificationController::class, 'markAllAsRead']);
    Route::patch('notifications/{id}/read', [NotificationController::class, 'markAsRead']);

    Route::post('fcm-token', [FcmTokenController::class, 'store']);
    Route::delete('fcm-token/{token}', [FcmTokenController::class, 'destroy'])->where('token', '.*');

    Route::get('/leads/export', [LeadController::class, 'export']);

    Route::get('leads', [LeadController::class, 'index']);
    Route::post('leads', [LeadController::class, 'store']);
    Route::get('leads/{id}', [LeadController::class, 'show']);
    Route::put('leads/{id}', [LeadController::class, 'update']);
    Route::delete('leads/{id}', [LeadController::class, 'destroy']);
    Route::patch('leads/{id}/status', [LeadController::class, 'updateStatus']);

    Route::get('leads/{id}/followups', [FollowupController::class, 'index']);
    Route::post('leads/{id}/followups', [FollowupController::class, 'store']);
    Route::get('followup-reminders', [FollowupReminderController::class, 'index']);
    Route::post('followup-reminders/{followup}/acknowledge', [FollowupReminderController::class, 'acknowledge']);

    Route::get('lead-field-settings', [LeadFieldSettingController::class, 'index']);
    Route::get('app-settings/post-call-sms', [AppSettingController::class, 'showSmsMessage']);

    Route::post('call-logs', [CallLogController::class, 'storeManual']);
    Route::patch('call-logs/{callLog}', [CallLogController::class, 'update']);

    Route::get('call-logs', [CallLogController::class, 'index']);
    // Legacy endpoint retained for the existing post-call messaging workflow.
    Route::post('leads/{lead}/call-log', [CallLogController::class, 'store']);

    // Admin-only
    Route::middleware('role:admin,client_admin')->group(function () {

        Route::post('leads/{id}/assign', [LeadController::class, 'assign']);
        Route::post('lead-field-settings', [LeadFieldSettingController::class, 'store']);
        Route::put('lead-field-settings', [LeadFieldSettingController::class, 'update']);
        Route::delete('lead-field-settings/{leadFieldSetting}', [LeadFieldSettingController::class, 'destroy']);
        Route::put('app-settings/post-call-sms', [AppSettingController::class, 'updateSmsMessage']);

        Route::get('users', [UserController::class, 'index']);
        Route::post('users', [UserController::class, 'store']);
        Route::put('users/{user}', [UserController::class, 'update']);
        Route::delete('users/{user}', [UserController::class, 'destroy']);
        Route::patch('users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        // Route::post('/test-lead', [LeadController::class, 'storeTestLead']);

    });
});
