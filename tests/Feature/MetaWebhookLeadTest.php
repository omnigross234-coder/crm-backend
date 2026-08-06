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

    public function test_meta_webhook_fetches_and_stores_real_lead_data(): void
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

        $this->postJson('/api/meta/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->postJson('/api/meta/webhook', $payload)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(1, Lead::count());

        $this->assertDatabaseHas('leads', [
            'meta_leadgen_id' => '123456789',
            'name' => 'Amit Sharma',
            'phone' => '9876543210',
            'email' => 'amit@example.com',
            'company' => 'Example Company',
            'city' => 'Mumbai',
            'requirement' => 'Need CRM setup',
            'source' => 'social',
            'status' => 'new',
            'priority' => 'warm',
            'created_by' => $user->id,
            'meta_form_id' => 'form-456',
            'meta_page_id' => 'page-789',
            'meta_ad_id' => 'ad-123',
            'meta_platform' => 'fb',
        ]);
    }

    public function test_meta_webhook_verification_uses_configured_verify_token(): void
    {
        config(['services.meta.verify_token' => 'my-secret-token']);

        $this->getJson('/api/meta/webhook?hub_mode=subscribe&hub_verify_token=my-secret-token&hub_challenge=abc123')
            ->assertOk()
            ->assertSee('abc123');
    }
}
