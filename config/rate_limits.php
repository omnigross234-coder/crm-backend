<?php

/**
 * Security Workstream A: restores rate limiting on the authentication-
 * adjacent public endpoints (login, forgot-password, reset-password,
 * trial-signup) that had it explicitly disabled ("TESTER-FREE MODE... —
 * restore before real launch", found live in routes/api.php).
 *
 * Every value below has a safe, meaningfully-protective default even if
 * the corresponding env var is completely absent — rate limiting on these
 * routes is structurally unconditional (the throttle:<name> middleware is
 * always attached in routes/api.php, in every environment); only the
 * NUMBERS here are environment-adjustable, never whether limiting itself
 * applies. This is deliberate: it is the only way to guarantee "tester-
 * free mode" cannot silently re-enable itself in production by a missing
 * or misconfigured env var (a missing var still gets the values below,
 * which are the exact figures already documented as the intended
 * production values before they were temporarily disabled).
 *
 * Defaults match the original inline comments in routes/api.php exactly:
 * `throttle:5,1` for login, `throttle:5,60` for the other three.
 */

return [
    'login' => [
        'max_attempts' => (int) env('RATE_LIMIT_LOGIN_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_LOGIN_DECAY_MINUTES', 1),
    ],

    'password_reset_request' => [
        'max_attempts' => (int) env('RATE_LIMIT_PASSWORD_RESET_REQUEST_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_PASSWORD_RESET_REQUEST_DECAY_MINUTES', 60),
    ],

    'password_reset_attempt' => [
        'max_attempts' => (int) env('RATE_LIMIT_PASSWORD_RESET_ATTEMPT_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_PASSWORD_RESET_ATTEMPT_DECAY_MINUTES', 60),
    ],

    'trial_signup' => [
        'max_attempts' => (int) env('RATE_LIMIT_TRIAL_SIGNUP_MAX_ATTEMPTS', 5),
        'decay_minutes' => (int) env('RATE_LIMIT_TRIAL_SIGNUP_DECAY_MINUTES', 60),
    ],
];
