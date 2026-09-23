<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use League\Flysystem\Filesystem;
use Masbug\Flysystem\GoogleDriveAdapter;
use Google\Client;
use Google\Service\Drive;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
$this->registerAuthenticationRateLimiters();

        try {
            Storage::extend('google', function ($app, $config) {
                $client = new Client();
                $client->setClientId($config['clientId']);
                $client->setClientSecret($config['clientSecret']);
                $client->refreshToken($config['refreshToken']);

                $service = new Drive($client);
                $adapter = new GoogleDriveAdapter($service, $config['folder'] ?? 'CRM_Backups');
                $filesystem = new Filesystem($adapter);

                return new LaravelFilesystemAdapter($filesystem, $adapter, $config);
            });
        } catch (\Throwable $e) {
            // don't crash the app if Google Drive isn't configured yet
        }
    }

    /**
     * Security Workstream A: restores rate limiting on login,
     * forgot-password, reset-password, and trial-signup — each of these
     * had it explicitly disabled in routes/api.php ("TESTER-FREE MODE...
     * restore before real launch"), confirmed unconditional (no
     * environment check) in every environment including production.
     *
     * Each limiter applies TWO independent limits — by IP and by the
     * submitted email — both of which must pass, matching Laravel's
     * documented pattern for returning an array of Limits from
     * RateLimiter::for(). This protects against both a single source
     * hammering many accounts (IP-based) and a distributed attack against
     * one specific account from many sources (email-based) — either alone
     * would miss one of those two shapes. The email is lower-cased/
     * trimmed before use as a limiter key so case variation can't be used
     * to bypass the per-account limit; the raw password/token is NEVER
     * part of any key.
     *
     * The email-based key is built from user *input*, not from whether an
     * account actually exists — so hitting the limit reveals nothing about
     * account existence (a nonexistent and existent email are throttled
     * identically), preserving the existing generic-response behavior in
     * AuthController/PasswordResetController.
     *
     * trial-signup is IP-only: admin_email already has a DB-level unique
     * constraint (TrialSignupController), so a repeat attempt with the
     * same email already fails validation before this limiter would add
     * anything — the actual abuse shape here (many different fake
     * companies from one source) is IP-shaped, not email-shaped.
     *
     * Numbers come from config/rate_limits.php, which has safe defaults
     * even if every env var is missing — see that file's own comment for
     * why this is deliberately fail-closed, not fail-open.
     */
    private function registerAuthenticationRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $config = config('rate_limits.login');
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('login-ip:'.$request->ip()),
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('login-email:'.$email),
            ];
        });

        RateLimiter::for('password-reset-request', function (Request $request) {
            $config = config('rate_limits.password_reset_request');
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('pwreset-request-ip:'.$request->ip()),
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('pwreset-request-email:'.$email),
            ];
        });

        RateLimiter::for('password-reset-attempt', function (Request $request) {
            $config = config('rate_limits.password_reset_attempt');
            $email = Str::lower(trim((string) $request->input('email')));

            return [
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('pwreset-attempt-ip:'.$request->ip()),
                Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                    ->by('pwreset-attempt-email:'.$email),
            ];
        });

        RateLimiter::for('trial-signup', function (Request $request) {
            $config = config('rate_limits.trial_signup');

            return Limit::perMinutes($config['decay_minutes'], $config['max_attempts'])
                ->by('trial-signup-ip:'.$request->ip());
        });
    }
}
