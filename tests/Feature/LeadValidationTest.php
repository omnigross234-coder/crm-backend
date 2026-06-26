<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_phone_must_contain_exactly_ten_digits(): void
    {
        $user = $this->user();

        foreach (['987654321', '98765432101', '98765abc10'] as $phone) {
            $this->actingAs($user)
                ->postJson('/api/leads', $this->validLead(['phone' => $phone]))
                ->assertUnprocessable()
                ->assertJsonPath('data.phone.0', 'Phone must contain exactly 10 digits.');
        }
    }

    public function test_name_rejects_numbers_on_create_and_update(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/api/leads', $this->validLead(['name' => 'Prasad 234']))
            ->assertUnprocessable()
            ->assertJsonPath(
                'data.name.0',
                'Name can contain letters, spaces, apostrophes, hyphens, and periods only.'
            );

        $lead = Lead::create([
            ...$this->validLead(),
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->putJson("/api/leads/{$lead->id}", $this->validLead([
                'name' => 'Updated 123',
            ]))
            ->assertUnprocessable()
            ->assertJsonPath(
                'data.name.0',
                'Name can contain letters, spaces, apostrophes, hyphens, and periods only.'
            );
    }

    public function test_valid_lead_can_be_created_and_keep_its_phone_when_edited(): void
    {
        $user = $this->user();

        $created = $this->actingAs($user)
            ->postJson('/api/leads', $this->validLead())
            ->assertCreated()
            ->assertJsonPath('data.phone', '9876543210');

        $leadId = $created->json('data.id');

        $this->actingAs($user)
            ->putJson("/api/leads/{$leadId}", $this->validLead(['name' => 'Updated Lead']))
            ->assertOk()
            ->assertJsonPath('data.name', 'Updated Lead')
            ->assertJsonPath('data.phone', '9876543210');
    }

    public function test_phone_remains_unique_between_leads(): void
    {
        $user = $this->user();

        Lead::create([
            ...$this->validLead(),
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->postJson('/api/leads', $this->validLead(['name' => 'Another Lead']))
            ->assertUnprocessable()
            ->assertJsonPath('data.phone.0', 'This phone number is already used by another lead.');
    }

    private function user(): User
    {
        return User::create([
            'name' => 'Sales User',
            'email' => 'sales-'.uniqid().'@example.com',
            'password' => 'password',
            'role' => 'sales',
            'status' => 'active',
        ]);
    }

    private function validLead(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Amit Sharma',
            'phone' => '9876543210',
            'email' => 'amit@example.com',
            'company' => 'Example Company',
            'source' => 'website',
            'status' => 'new',
            'priority' => 'warm',
            'remarks' => 'Interested in the product.',
        ], $overrides);
    }
}
