<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FacebookPage;
use App\Models\Lead;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MetaWebhookController extends Controller
{
    public function verify(Request $request)
    {
        $expected = (string) config('services.meta.verify_token');
        $provided = (string) $request->get('hub_verify_token', '');

        if ($expected !== '' && hash_equals($expected, $provided)) {
            return response($request->get('hub_challenge'), 200);
        }

        return response('Invalid verify token', 403);
    }

    public function handle(Request $request)
    {
        if (! $this->hasValidSignature($request)) {
            Log::warning('Meta webhook: invalid signature, rejecting payload.');
            return response()->json(['success' => false], 403);
        }

        $entries = $request->input('entry', []);

        foreach ($entries as $entry) {
            $pageId = $entry['id'] ?? null;
            $page = FacebookPage::where('page_id', $pageId)->first();

            if (! $page) {
                Log::warning('Meta webhook: no client owns this page', ['page_id' => $pageId]);
                continue;
            }

            foreach ($entry['changes'] ?? [] as $change) {
                $leadgenId = $change['value']['leadgen_id'] ?? null;
                if (! $leadgenId) {
                    continue;
                }

                try {
                    $this->processLeadgenEvent($leadgenId, $page);
                } catch (Throwable $e) {
                    // Isolate failures per-lead so one bad entry doesn't fail
                    // the whole batch and trigger Meta to retry everything.
                    Log::error('Meta webhook: failed to process leadgen event', [
                        'leadgen_id' => $leadgenId,
                        'page_id' => $pageId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json(['success' => true]);
    }

    private function processLeadgenEvent(string $leadgenId, FacebookPage $page): void
    {
        $leadData = $this->fetchLeadDetails($leadgenId, $page->page_access_token);
        if (! $leadData) {
            return;
        }

        $createdBy = $this->createdByUserId($page->client_id);
        if (! $createdBy) {
            Log::error('Meta webhook: no user available to attribute lead to.', [
                'leadgen_id' => $leadgenId,
                'client_id' => $page->client_id,
            ]);
            return;
        }

        // updateOrCreate on meta_leadgen_id prevents duplicate leads when
        // Meta retries webhook delivery for the same event.
        $lead = Lead::updateOrCreate(
            ['meta_leadgen_id' => $leadgenId],
            [
                'client_id' => $page->client_id,
                'name' => $leadData['full_name'] ?? 'Facebook Lead',
                'phone' => $leadData['phone_number'] ?? '',
                'email' => $leadData['email'] ?? null,
                'source' => 'facebook',
                'status' => 'new',
                'priority' => 'warm',
                'created_by' => $createdBy,
                'meta_page_id' => $page->page_id,
            ]
        );

        ActivityLogger::log('lead.created_from_meta', $lead, ['page_id' => $page->page_id]);
    }

    /**
     * Picks a user within the owning client to attribute the lead's
     * created_by to. Adjust this to whatever "system/default owner"
     * convention your client_admins expect (e.g. the client's admin user).
     */
    private function createdByUserId(int $clientId): ?int
    {
        return User::where('client_id', $clientId)
            ->orderBy('id')
            ->value('id');
    }

    private function hasValidSignature(Request $request): bool
    {
        $appSecret = config('services.meta.app_secret');
        if (! $appSecret) {
            // No secret configured — treat as not enforced, but log it so
            // this doesn't go unnoticed in production.
            Log::warning('Meta webhook: app_secret not configured, skipping signature check.');
            return true;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $appSecret);

        return hash_equals($expected, $signature);
    }

    private function fetchLeadDetails(string $leadgenId, string $accessToken): ?array
    {
        $response = Http::get("https://graph.facebook.com/v19.0/{$leadgenId}", [
            'access_token' => $accessToken,
        ]);

        if (! $response->ok()) {
            Log::error('Meta lead fetch failed', ['leadgen_id' => $leadgenId, 'body' => $response->body()]);
            return null;
        }

        $fields = collect($response->json('field_data', []))
            ->mapWithKeys(fn ($f) => [$f['name'] => $f['values'][0] ?? null]);

        return [
            'full_name' => $fields->get('full_name') ?? $fields->get('name'),
            'phone_number' => $fields->get('phone_number'),
            'email' => $fields->get('email'),
        ];
    }
}