<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    // Mirrors AppSettingController::DEFAULT_SMS_MESSAGE, which is private
    // and so can't be referenced directly from this test.
    private const DEFAULT_SMS_MESSAGE = 'Thank you for your time. We will contact you shortly.';

    public function test_authenticated_users_can_read_the_post_call_sms_message(): void
    {
        $client = Client::factory()->create();
        $sales = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Sales User',
            'email' => 'sales-read@example.com',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'active',
        ]);

        $this->actingAs($sales)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJsonPath('data.message', self::DEFAULT_SMS_MESSAGE);
    }

    public function test_only_admins_can_change_the_post_call_sms_message(): void
    {
        $client = Client::factory()->create();
        $sales = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Sales User',
            'email' => 'sales-update@example.com',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'active',
        ]);
        $admin = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Admin User',
            'email' => 'admin-update@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($sales)
            ->putJson('/api/app-settings/post-call-sms', [
                'message' => 'Sales users must not change this.',
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->putJson('/api/app-settings/post-call-sms', [
                'message' => 'Thank you for speaking with us.',
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'Thank you for speaking with us.');

        $this->actingAs($sales)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJsonPath('data.message', 'Thank you for speaking with us.');
    }

    /**
     * FLT-F04 (Phase 1 audit): the mobile app has always sent these 7
     * fields (in addition to sms_message/whatsapp_message) on every save
     * - the backend used to silently accept and drop all of them. Confirmed
     * live against the real server before this fix: a PUT with all 9
     * fields returned success, but the very next GET only echoed back
     * message/sms_message/whatsapp_message.
     */
    public function test_all_nine_settings_fields_persist_across_a_fresh_request(): void
    {
        $client = Client::factory()->create();
        $admin = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Admin User',
            'email' => 'admin-full-settings@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/app-settings/post-call-sms', [
                'sms_message' => 'Custom SMS msg',
                'whatsapp_message' => 'Custom WA msg',
                'gap' => '1_hour',
                'incoming_enabled' => false,
                'outgoing_enabled' => false,
                'missed_enabled' => false,
                'sms_enabled' => false,
                'unknown_only' => true,
                'sim_slot' => 2,
            ])
            ->assertOk();

        // A fresh, independent request - not a reused response body - is
        // what actually proves persistence rather than an echo.
        $this->actingAs($admin)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'sms_message' => 'Custom SMS msg',
                    'whatsapp_message' => 'Custom WA msg',
                    'gap' => '1_hour',
                    'incoming_enabled' => false,
                    'outgoing_enabled' => false,
                    'missed_enabled' => false,
                    'sms_enabled' => false,
                    'unknown_only' => true,
                    'sim_slot' => 2,
                ],
            ]);
    }

    public function test_a_brand_new_client_gets_defaults_matching_the_apps_own_assumptions(): void
    {
        $client = Client::factory()->create();
        $admin = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Admin User',
            'email' => 'admin-fresh-settings@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'gap' => '5_days',
                    'sim_slot' => 1,
                    'incoming_enabled' => true,
                    'outgoing_enabled' => true,
                    'missed_enabled' => true,
                    'sms_enabled' => true,
                    'unknown_only' => false,
                ],
            ]);
    }

    public function test_a_partial_update_does_not_reset_other_fields_to_defaults(): void
    {
        $client = Client::factory()->create();
        $admin = User::query()->create([
            'client_id' => $client->id,
            'name' => 'Admin User',
            'email' => 'admin-partial-settings@example.com',
            'password' => 'password',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin)->putJson('/api/app-settings/post-call-sms', [
            'sim_slot' => 2,
            'unknown_only' => true,
        ])->assertOk();

        // Touching only gap must not clobber sim_slot/unknown_only set above.
        $this->actingAs($admin)->putJson('/api/app-settings/post-call-sms', [
            'gap' => '1_day',
        ])->assertOk();

        $this->actingAs($admin)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJson([
                'data' => [
                    'gap' => '1_day',
                    'sim_slot' => 2,
                    'unknown_only' => true,
                ],
            ]);
    }
}
