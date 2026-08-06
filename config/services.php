<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'firebase' => [
    'project_id'  => env('FIREBASE_PROJECT_ID'),
    'credentials' => env('FIREBASE_CREDENTIALS'),   // ← key name must be 'credentials'
    'verify_ssl'  => env('FIREBASE_VERIFY_SSL', true),
],

'twilio' => [
    'sid'            => env('TWILIO_SID'),
    'token'          => env('TWILIO_TOKEN'),
    'from'           => env('TWILIO_FROM'),
    'whatsapp_from'  => env('TWILIO_WHATSAPP_FROM'),
],

    'meta' => [
        'verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'page_access_token' => env('META_PAGE_ACCESS_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
        'graph_version' => env('META_GRAPH_VERSION', 'v20.0'),
        'created_by_user_id' => env('META_CREATED_BY_USER_ID', 1),
        
    ],

    'cron' => [
        'reminder_key' => env('REMINDER_RUN_KEY'),
        'backup_key' => env('BACKUP_RUN_KEY'),
    ],

    'mysqldump' => [
        'path' => env('MYSQLDUMP_PATH', 'mysqldump'),
    ],
];
