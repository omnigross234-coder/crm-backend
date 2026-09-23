<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Phase 2 Foundation: read-only Super Admin audit-log viewer.
 *
 * The activity_logs table and its writer (ActivityLogger) already existed
 * and are already wired into billing/client/lead/subscription actions —
 * this controller only adds the missing read path. It does not change
 * anything about how or when entries are written.
 *
 * Route-level 'super_admin' middleware (see routes/api.php) is the actual
 * authorization boundary; this controller does not additionally check the
 * role itself, matching the existing pattern used by ClientController and
 * BackupController under the same route group.
 */
class AuditLogController extends Controller
{
    private const SORTABLE_COLUMNS = ['created_at', 'action', 'module', 'client_id', 'user_id'];

    /**
     * Allowlisted resource shorthands -> the FQCN stored in subject_type
     * (set via get_class($subject) in ActivityLogger::log). Matched by
     * exact equality rather than a LIKE/backslash pattern: subject_type
     * contains namespace separators ("App\Models\Lead"), and the backslash
     * escaping rules for LIKE differ between SQLite (used in tests) and
     * MySQL (production) — an exact-match allowlist sidesteps that
     * entirely instead of risking a filter that silently behaves
     * differently between the two.
     */
    private const RESOURCE_TYPES = [
        'client' => \App\Models\Client::class,
        'lead' => \App\Models\Lead::class,
        'subscription' => \App\Models\Subscription::class,
        'facebookpage' => \App\Models\FacebookPage::class,
        // Phase 6 (Super Admin User Management): so a user detail page's
        // audit panel can find user.created/updated/activated/etc events
        // via resource=user&subject_id=, the same pattern already used by
        // the Phase 5 tenant detail page for resource=client.
        'user' => \App\Models\User::class,
    ];

    /**
     * Keys that must never be echoed back from the `meta` JSON blob, even
     * though nothing currently writes them there (defense in depth — this
     * column accepts arbitrary data from any future ActivityLogger::log()
     * call, and this reader has no other way to guarantee it stays safe).
     */
    private const REDACTED_META_KEYS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'api_key', 'secret', 'authorization',
        'fcm_token', 'access_token', 'refresh_token',
    ];

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('resource')) {
            $request->merge(['resource' => strtolower((string) $request->input('resource'))]);
        }

        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'sort_by' => ['sometimes', 'string', Rule::in(self::SORTABLE_COLUMNS)],
            'sort_dir' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
            'from' => 'sometimes|date',
            'to' => 'sometimes|date|after_or_equal:from',
            'user_id' => 'sometimes|integer|exists:users,id',
            'client_id' => 'sometimes|integer|exists:clients,id',
            'action' => 'sometimes|string|max:191',
            'resource' => ['sometimes', 'string', Rule::in(array_keys(self::RESOURCE_TYPES))],
            'subject_id' => 'sometimes|integer',
            'search' => 'sometimes|string|max:191',
        ]);

        $perPage = $validated['per_page'] ?? 25;
        $sortBy = $validated['sort_by'] ?? 'created_at';
        $sortDir = $validated['sort_dir'] ?? 'desc';

        $query = ActivityLog::query()->with(['user:id,name,email', 'client:id,name']);

        if (! empty($validated['from'])) {
            $query->whereDate('created_at', '>=', $validated['from']);
        }
        if (! empty($validated['to'])) {
            $query->whereDate('created_at', '<=', $validated['to']);
        }
        if (! empty($validated['user_id'])) {
            $query->where('user_id', $validated['user_id']);
        }
        if (! empty($validated['client_id'])) {
            $query->where('client_id', $validated['client_id']);
        }
        if (! empty($validated['action'])) {
            $query->where('action', $validated['action']);
        }
        if (! empty($validated['resource'])) {
            $query->where('subject_type', self::RESOURCE_TYPES[$validated['resource']]);

            // Phase 5: super-admin-initiated actions (e.g. client.updated)
            // log with client_id=null (ActivityLogger::log records the
            // ACTOR's client_id, which is null for a super_admin) — so
            // client_id alone can't find a tenant's own lifecycle events.
            // subject_id + resource together can. Only meaningful paired
            // with `resource` (a bare subject_id is ambiguous across
            // subject types), so it's intentionally ignored otherwise.
            if (! empty($validated['subject_id'])) {
                $query->where('subject_id', $validated['subject_id']);
            }
        }
        if (! empty($validated['search'])) {
            $query->where('description', 'like', '%'.$validated['search'].'%');
        }

        // id is the unique tie-breaker: created_at has one-second resolution
        // (and the other sortable columns repeat heavily), so without it rows
        // sharing a value are ordered arbitrarily and offset pagination
        // drops some rows and repeats others across pages.
        $logs = $query->orderBy($sortBy, $sortDir)->orderBy('id', $sortDir)->paginate($perPage);

        $logs->getCollection()->transform(function (ActivityLog $log) {
            return [
                'id' => $log->id,
                'action' => $log->action,
                'module' => $log->module,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'description' => $log->description,
                'meta' => $this->redactMeta($log->meta),
                'ip_address' => $log->ip_address,
                'actor' => $log->user ? [
                    'id' => $log->user->id,
                    'name' => $log->user->name,
                    'email' => $log->user->email,
                ] : null,
                'client' => $log->client ? [
                    'id' => $log->client->id,
                    'name' => $log->client->name,
                ] : null,
                'created_at' => $log->created_at,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $meta
     * @return array<string, mixed>|null
     */
    private function redactMeta(?array $meta): ?array
    {
        if (! $meta) {
            return $meta;
        }

        foreach (array_keys($meta) as $key) {
            foreach (self::REDACTED_META_KEYS as $sensitiveKey) {
                if (stripos((string) $key, $sensitiveKey) !== false) {
                    $meta[$key] = '[redacted]';
                    break;
                }
            }
        }

        return $meta;
    }
}
