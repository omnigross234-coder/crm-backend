<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaWebhookLeadTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Phase 2A / SEC-F07: the signature check now fails CLOSED when
     * META_APP_SECRET is unconfigured, so every test in this file must set
     * an explicit, known secret and sign its own payload — it can no longer
     * depend on (or be accidentally broken by) whatever happens to be in the
     * developer's local .env (see PHASE1-AUDIT-REPORT.md TEST-01, and
     * PHASE2A-REMEDIATION-REPORT.md).
     */
    private const TEST_APP_SECRET = 'phase2a-test-meta-app-secret';

    /**
     * NOTE (Phase 2A): this test's original name/assertions ("...stores real
     * lead data") predate LeadController::resolveClientIdForPage() being
     * hardened into an unconditional `return null;` stub with an explicit
     * "refuse to create an untenanted lead" guard in storeMetaLead() — i.e.
     * this test was already asserting an outcome the current code
     * intentionally no longer produces, independent of anything in this
     * phase's SEC-F07 signature fix (confirmed via the log output: the
     * signature check passes, then storeMetaLead() logs "no client mapped to
     * this Meta page" and safely drops the lead). Implementing the actual
     * page->client mapping is COMPL-F03 in PHASE1-AUDIT-REPORT.md and is
     * explicitly out of scope for Phase 2A (see PHASE2A-REMEDIATION-REPORT.md
     * "Remaining Findings"). This test is corrected here to assert the real,
     * current, intentional behavior — a validly-signed webhook for an
     * unmapped page is accepted but safely refuses to create an orphaned
     * lead — rather than silently passing on a description that no longer
     * matches what the code does.
     */
    public function test_meta_webhook_with_valid_signature_is_accepted_but_unmapped_page_does_not_create_a_lead(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        config([
            'services.meta.page_access_token' => 'test-page-token',
            'services.meta.created_by_user_id' => $user->id,
            'services.meta.graph_version' => 'v20.0',
            'services.meta.app_secret' => self::TEST_APP_SECRET,
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => '123456789',
                'created_time' => '2026-07-01T10:00:00+0000',
                'ad_id' => 'ad-123',
                'form_id' => 'form-456',
                'platform' => 'fb',
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Amit Sharma']],
                    ['name' => 'phone_number', 'values' => ['9876543210']],
                    ['name' => 'email', 'values' => ['amit@example.com']],
                    ['name' => 'company_name', 'values' => ['Example Company']],
                    ['name' => 'city', 'values' => ['Mumbai']],
                    ['name' => 'requirement', 'values' => ['Need CRM setup']],
                ],
            ]),
        ]);

        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-789',
                'time' => 1782900000,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => '123456789',
                        'page_id' => 'page-789',
                        'form_id' => 'form-456',
                        'ad_id' => 'ad-123',
                    ],
                ]],
            ]],
        ];

        // Signature verification (SEC-F07's concern) succeeds — the request
        // is accepted with a 200 either way, matching Meta's own expectation
        // that a webhook receiver acknowledges receipt regardless of what it
        // internally decides to do with the event.
        $this->signedPost($payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        // But since no client is mapped to 'page-789' (resolveClientIdForPage
        // is an unimplemented TODO stub — COMPL-F03, out of scope here), the
        // lead is intentionally NOT created rather than being saved with no
        // tenant.
        $this->assertSame(0, Lead::count());
        $this->assertDatabaseMissing('leads', ['meta_leadgen_id' => '123456789']);
    }

    public function test_meta_webhook_verification_uses_configured_verify_token(): void
    {
        config(['services.meta.verify_token' => 'my-secret-token']);

        $this->getJson('/api/meta/webhook?hub_mode=subscribe&hub_verify_token=my-secret-token&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }

    public function test_invalid_signature_is_rejected_and_no_lead_is_created(): void
    {
        config(['services.meta.app_secret' => self::TEST_APP_SECRET]);
        $payload = $this->leadgenPayload();

        $this->postJson('/api/meta/webhook', $payload, [
            'X-Hub-Signature-256' => 'sha256=not-a-real-signature',
        ])->assertStatus(403);

        $this->assertSame(0, Lead::count());
    }

    public function test_missing_signature_header_is_rejected(): void
    {
        config(['services.meta.app_secret' => self::TEST_APP_SECRET]);
        $payload = $this->leadgenPayload();

        $this->postJson('/api/meta/webhook', $payload)->assertStatus(403);

        $this->assertSame(0, Lead::count());
    }

    public function test_missing_app_secret_fails_closed_even_with_a_signature_header(): void
    {
        // SEC-F07: an attacker cannot bypass verification by sending an
        // arbitrary (or even correctly-shaped) signature header when the
        // server itself has no configured secret to check it against —
        // the endpoint must reject regardless of what header is sent.
        config(['services.meta.app_secret' => null]);
        $payload = $this->leadgenPayload();

        $this->postJson('/api/meta/webhook', $payload, [
            'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', json_encode($payload), 'irrelevant'),
        ])->assertStatus(403);

        $this->assertSame(0, Lead::count());
    }

    private function leadgenPayload(): array
    {
        return [
            'object' => 'page',
            'entry' => [[
                'id' => 'page-789',
                'time' => 1782900000,
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => '123456789',
                        'page_id' => 'page-789',
                        'form_id' => 'form-456',
                        'ad_id' => 'ad-123',
                    ],
                ]],
            ]],
        ];
    }

    /**
     * OBS-F04 (Phase 1 audit, Phase 2C Workstream F): the webhook-received
     * and fetch-failure log calls used to dump the raw request body /
     * response body wholesale. Reproduced against the real payload shape
     * (see the test above) and confirmed the webhook body itself never
     * carries lead PII - but logging it unfiltered was still the unsafe
     * pattern the finding was about. This locks in that no PII value
     * (name/phone/email, however the fetch turns out) ever appears in any
     * logged message or context, while the useful debugging fields
     * (leadgen_id) still do.
     */
    public function test_no_pii_is_logged_for_a_webhook_that_successfully_fetches_lead_data(): void
    {
        $user = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        config([
            'services.meta.page_access_token' => 'test-page-token',
            'services.meta.created_by_user_id' => $user->id,
            'services.meta.graph_version' => 'v20.0',
            'services.meta.app_secret' => self::TEST_APP_SECRET,
        ]);

        Http::fake([
            'https://graph.facebook.com/*' => Http::response([
                'id' => '123456789',
                'form_id' => 'form-456',
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Amit Sharma']],
                    ['name' => 'phone_number', 'values' => ['9876543210']],
                    ['name' => 'email', 'values' => ['amit@example.com']],
                ],
            ]),
        ]);

        // Route the 'stack'/default channel to a real, dedicated log file
        // for this one test, so the actual formatted log lines (message +
        // context, exactly as they'd appear in storage/logs/laravel.log)
        // can be inspected directly rather than fighting Mockery's spy
        // introspection API for something a plain file read answers just
        // as well.
        $logPath = storage_path('logs/phase2c_workstream_f_obs_f04_test.log');
        @unlink($logPath);
        config(['logging.channels.single.path' => $logPath]);
        config(['logging.default' => 'single']);

        $this->signedPost([
            'object' => 'page',
            'entry' => [[
                'id' => 'page-789',
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => '123456789',
                        'page_id' => 'page-789',
                        'form_id' => 'form-456',
                    ],
                ]],
            ]],
        ])->assertOk();

        $this->assertFileExists($logPath, 'Expected the webhook flow to have logged something.');
        $logContent = file_get_contents($logPath);

        foreach (['Amit Sharma', '9876543210', 'amit@example.com'] as $pii) {
            $this->assertStringNotContainsString($pii, $logContent, "PII value '{$pii}' must not appear in the log output.");
        }

        // The fix must not have made the logs useless - the leadgen_id
        // (routing metadata, not PII) should still be there.
        $this->assertStringContainsString('123456789', $logContent);

        @unlink($logPath);
    }

    private function signedPost(array $payload)
    {
        $rawBody = json_encode($payload);
        $signature = 'sha256=' . hash_hmac('sha256', $rawBody, self::TEST_APP_SECRET);

        return $this->postJson('/api/meta/webhook', $payload, [
            'X-Hub-Signature-256' => $signature,
        ]);
    }
}
