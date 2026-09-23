<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuthEvent;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Super Admin Security Center, Phase 1 (backend foundation only — no UI).
 *
 * Deliberately reuses every existing security data source from
 * Workstreams A-E rather than inventing a parallel system:
 *  - auth_events (Workstream C) for authentication activity — this is
 *    the one domain with no existing reader, so authEvents() is a real
 *    new paginated listing, built on the exact same allowlisted-sort /
 *    validated-filter pattern already proven in AuditLogController.
 *  - personal_access_tokens (Sanctum / Workstream D) for a platform-wide
 *    active-session COUNT only — a global per-token browser was
 *    explicitly deferred by Workstream D's own report as a future
 *    product decision, not re-opened here.
 *  - activity_logs (pre-existing + Workstream E's backup entries) for
 *    both a bounded activity count AND the latest backup outcome —
 *    reusing the already-audited backup.created/backup.failed rows
 *    instead of calling BackupService::listBackups() live (a real
 *    external Google Drive API call with no place in a bounded,
 *    always-fast overview endpoint). GET /api/audit-logs already exists
 *    for full activity-log browsing/redaction and is NOT duplicated here.
 *  - config/rate_limits.php (Workstream A) and the hardcoded facts of
 *    SecurityHeaders (Workstream B) — static reads, zero DB queries.
 *  - the same DB-connectivity check GET /api/health/ready already
 *    performs, called inline rather than via a self-referential HTTP call.
 *
 * No security score, risk rating, or invented metric is ever computed —
 * every field below is either a direct count, a config value, or a
 * hardcoded fact already true of existing middleware/config.
 */
class SecurityCenterController extends Controller
{
    private const MAX_RANGE_DAYS = 90;

    private const AUTH_EVENT_TYPES = [
        'login_success',
        'login_failed',
        'logout',
        'password_reset_requested',
        'password_reset_succeeded',
        'password_reset_failed',
    ];

    private const AUTH_EVENTS_SORTABLE_COLUMNS = ['created_at', 'event', 'result'];

    public function overview(Request $request): JsonResponse
    {
        $validator = $this->dateRangeValidator($request);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        [$from, $to] = $this->resolveDateRange($validator->validated());

        return response()->json([
            'success' => true,
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'authentication' => $this->authenticationCounts($from, $to),
                'sessions' => [
                    'active_count' => PersonalAccessToken::query()
                        ->where(function ($q) {
                            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        })
                        ->count(),
                ],
                'audit_activity' => [
                    'recent_count' => ActivityLog::query()
                        ->whereBetween('created_at', [$from, $to])
                        ->count(),
                ],
                'backups' => [
                    'latest' => $this->latestBackupState(),
                ],
                'rate_limiting' => $this->rateLimitingStatus(),
                'security_headers' => $this->securityHeadersStatus(),
                'health' => $this->healthStatus(),
            ],
        ]);
    }

    public function authEvents(Request $request): JsonResponse
    {
        $validator = $this->dateRangeValidator($request, [
            'event' => ['sometimes', 'string', Rule::in(self::AUTH_EVENT_TYPES)],
            'result' => ['sometimes', 'string', Rule::in(['success', 'failure'])],
            'client_id' => ['sometimes', 'integer', 'exists:clients,id'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort_by' => ['sometimes', 'string', Rule::in(self::AUTH_EVENTS_SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);
        if ($validator->fails()) {
            return $this->validationErrorResponse($validator);
        }

        $validated = $validator->validated();
        [$from, $to] = $this->resolveDateRange($validated);

        $perPage = $validated['per_page'] ?? 25;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $query = AuthEvent::query()
            ->with(['user:id,name,email', 'client:id,name'])
            ->whereBetween('created_at', [$from, $to]);

        if (! empty($validated['event'])) {
            $query->where('event', $validated['event']);
        }
        if (! empty($validated['result'])) {
            $query->where('result', $validated['result']);
        }
        if (! empty($validated['client_id'])) {
            // Narrows an already-authorized Super Admin's own platform-wide
            // view only — never an authorization check. Access to this
            // endpoint is already fully gated by the super_admin route
            // middleware regardless of this value.
            $query->where('client_id', $validated['client_id']);
        }

        // id is the unique tie-breaker: created_at has one-second resolution
        // (and event/result only a handful of values), so without it rows
        // sharing a value are ordered arbitrarily and offset pagination
        // drops some rows and repeats others across pages.
        $events = $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir)->paginate($perPage);

        $events->getCollection()->transform(fn (AuthEvent $event) => [
            'id' => $event->id,
            'event' => $event->event,
            'result' => $event->result,
            'failure_reason' => $event->failure_reason,
            'login_identifier' => $event->login_identifier,
            'ip_address' => $event->ip_address,
            'user' => $event->user ? [
                'id' => $event->user->id,
                'name' => $event->user->name,
                'email' => $event->user->email,
            ] : null,
            'client' => $event->client ? [
                'id' => $event->client->id,
                'name' => $event->client->name,
            ] : null,
            'created_at' => $event->created_at,
        ]);

        return response()->json([
            'success' => true,
            'data' => $events->items(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'last_page' => $events->lastPage(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function authenticationCounts(Carbon $from, Carbon $to): array
    {
        $counts = AuthEvent::query()
            ->whereBetween('created_at', [$from, $to])
            ->select('event', DB::raw('count(*) as total'))
            ->groupBy('event')
            ->pluck('total', 'event');

        $result = [];
        foreach (self::AUTH_EVENT_TYPES as $eventType) {
            $result["{$eventType}_count"] = (int) ($counts[$eventType] ?? 0);
        }

        return $result;
    }

    /**
     * Sourced from the already-audited activity_logs rows Workstream E's
     * BackupController/BackupDatabase write — never a live Google Drive
     * call (slow, external, and unnecessary: this app already has a
     * trustworthy, fast, local record of the outcome of the last attempt).
     *
     * @return array{status: string, created_at: string, triggered_via: ?string}|null
     */
    private function latestBackupState(): ?array
    {
        $latest = ActivityLog::query()
            ->whereIn('action', ['backup.created', 'backup.failed'])
            ->orderByDesc('created_at')
            ->first();

        if (! $latest) {
            return null;
        }

        return [
            'status' => str_replace('backup.', '', $latest->action),
            'created_at' => $latest->created_at->toIso8601String(),
            'triggered_via' => $latest->meta['triggered_via'] ?? null,
        ];
    }

    /**
     * @return array<string, array{enabled: bool, max_attempts: int, decay_minutes: int}>
     */
    private function rateLimitingStatus(): array
    {
        $limiters = ['login', 'password_reset_request', 'password_reset_attempt', 'trial_signup'];
        $status = [];

        foreach ($limiters as $limiter) {
            $config = config("rate_limits.{$limiter}");
            // The throttle:<name> middleware is unconditionally attached
            // in routes/api.php in every environment (Workstream A) —
            // there is no "disabled" state for any of these four
            // limiters, so this is a stated fact, not an inference.
            $status[$limiter] = [
                'enabled' => true,
                'max_attempts' => (int) $config['max_attempts'],
                'decay_minutes' => (int) $config['decay_minutes'],
            ];
        }

        return $status;
    }

    /**
     * Mirrors the literal, hardcoded facts already true of
     * App\Http\Middleware\SecurityHeaders::handle() — not measured from a
     * live response, since the middleware's own source is the more
     * direct, always-correct source of truth for what it unconditionally
     * does. Strict-Transport-Security is represented as a descriptive
     * string, never a flat boolean, because it is genuinely conditional
     * on whether the current request is HTTPS — stating it as `true`
     * unconditionally would misrepresent real, non-HTTPS traffic.
     *
     * @return array<string, bool|string>
     */
    private function securityHeadersStatus(): array
    {
        return [
            'x_content_type_options' => true,
            'x_frame_options' => true,
            'referrer_policy' => true,
            'permissions_policy' => true,
            'strict_transport_security' => 'enabled_when_https',
        ];
    }

    /**
     * The identical DB-connectivity check GET /api/health/ready already
     * performs, called inline rather than via a self-referential HTTP
     * request. Any failure is caught the same way that route already
     * does — never a raw exception surfaced to the caller.
     *
     * @return array{up: bool, database: bool}
     */
    private function healthStatus(): array
    {
        $databaseUp = true;

        try {
            DB::select('select 1');
        } catch (Throwable) {
            $databaseUp = false;
        }

        return [
            'up' => true,
            'database' => $databaseUp,
        ];
    }

    private function dateRangeValidator(Request $request, array $extraRules = []): Validator
    {
        return validator($request->all(), array_merge([
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
        ], $extraRules), [], [])
            ->after(function ($validator) {
                // Malformed dates and to < from (both supplied) are already
                // reported by the rules above; there is nothing sound to resolve.
                if ($validator->errors()->hasAny(['from', 'to'])) {
                    return;
                }

                // The limit applies to the range the query will actually use
                // — defaults included — not to whichever parameters happened
                // to be sent. `from` alone resolves to [from, end of today],
                // so `?from=1900-01-01` is a 46,000-day range.
                $data = $validator->getData();
                [$from, $to] = $this->resolveDateRange([
                    'from' => $data['from'] ?? null,
                    'to' => $data['to'] ?? null,
                ]);
                $errorKey = isset($data['to']) ? 'to' : 'from';

                // Reachable only via `from` without `to` (a future `from`
                // resolves to a range that ends before it starts).
                if ($from->gt($to)) {
                    $validator->errors()->add('from', 'The from date must not be later than the end of the range.');

                    return;
                }

                // Compared as calendar dates (both at UTC midnight) so the
                // result is an exact whole number of days whatever the
                // application timezone. absolute: true is required —
                // Carbon's diffInDays() is SIGNED (the footgun fixed in
                // PasswordResetController::resetPassword(), SEC-F05).
                $days = Carbon::parse($from->toDateString(), 'UTC')
                    ->diffInDays(Carbon::parse($to->toDateString(), 'UTC'), absolute: true);

                if ($days > self::MAX_RANGE_DAYS) {
                    $validator->errors()->add($errorKey, 'The date range must not exceed '.self::MAX_RANGE_DAYS.' days.');
                }
            });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Carbon, 1: Carbon}
     */
    private function resolveDateRange(array $validated): array
    {
        $timezone = config('app.timezone');

        $to = isset($validated['to'])
            ? Carbon::parse($validated['to'], $timezone)->endOfDay()
            : Carbon::now($timezone)->endOfDay();

        $from = isset($validated['from'])
            ? Carbon::parse($validated['from'], $timezone)->startOfDay()
            : (clone $to)->subDays(30)->startOfDay();

        return [$from, $to];
    }

    private function validationErrorResponse(Validator $validator): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'data' => $validator->errors(),
        ], 422);
    }
}
