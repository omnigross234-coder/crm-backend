<?php

use App\Console\Commands\SendFollowupReminders;
use App\Http\Middleware\RoleMiddleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([
        SendFollowupReminders::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('followups:send-reminders')
            ->everyMinute()
            ->withoutOverlapping(2);

        $schedule->command('db:backup')
            ->dailyAt('18:00')
            ->withoutOverlapping(120);
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(HandleCors::class);
        $middleware->redirectGuestsTo(null);

        // Security Workstream B: prepended LAST, making it the outermost
        // middleware layer of all (order: SecurityHeaders -> AssignRequestId
        // -> HandleCors -> ...) so it wraps literally every response,
        // including one HandleCors short-circuits early for an OPTIONS
        // preflight (confirmed by testing: appending this instead put it
        // after HandleCors in the pipeline, and it never ran for OPTIONS
        // because HandleCors returns its preflight response without
        // calling $next()). The same "after" pattern AssignRequestId above
        // already uses correctly reaches exception-rendered responses too
        // (401/403/404/429 all verified to carry these headers).
        $middleware->prepend(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'role'        => \App\Http\Middleware\EnsureRole::class,
            'tenant'      => \App\Http\Middleware\IdentifyTenant::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'account.active' => \App\Http\Middleware\EnsureAccountIsActive::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscriptionIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(function (Request $request, Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });
    })->create();
