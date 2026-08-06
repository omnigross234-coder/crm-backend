<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AppSettingController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_can_read_the_post_call_sms_message(): void
    {
        $sales = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-read@example.com',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'active',
        ]);

        $this->actingAs($sales)
            ->getJson('/api/app-settings/post-call-sms')
            ->assertOk()
            ->assertJsonPath(
                'data.message',
                AppSettingController::DEFAULT_POST_CALL_SMS_MESSAGE,
            );
    }

    public function test_only_admins_can_change_the_post_call_sms_message(): void
    {
        $sales = User::query()->create([
            'name' => 'Sales User',
            'email' => 'sales-update@example.com',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'active',
        ]);
        $admin = User::query()->create([
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
}
