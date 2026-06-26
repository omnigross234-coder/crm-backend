<?php

namespace Tests\Feature;

use App\Models\Followup;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFollowupCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_counts_distinct_leads_with_followups_today(): void
    {
        $admin = $this->user('admin');
        $sales = $this->user('sales');
        $firstLead = $this->lead($sales, '9876543210');
        $secondLead = $this->lead($sales, '9876543211');

        $this->followup($firstLead, $sales);
        $this->followup($firstLead, $sales);
        $this->followup($secondLead, $sales);

        $this->actingAs($admin)
            ->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.followups_today', 2);

        $this->actingAs($admin)
            ->getJson('/api/leads?followups_today=1')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_sales_count_matches_assigned_leads_list(): void
    {
        $sales = $this->user('sales');
        $otherSales = $this->user('sales');
        $assignedLead = $this->lead($sales, '9876543210');
        $otherLead = $this->lead($otherSales, '9876543211');

        $this->followup($assignedLead, $otherSales);
        $this->followup($otherLead, $sales);

        $this->actingAs($sales)
            ->getJson('/api/dashboard/stats')
            ->assertOk()
            ->assertJsonPath('data.followups_today', 1);

        $this->actingAs($sales)
            ->getJson('/api/leads?followups_today=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    private function user(string $role): User
    {
        return User::create([
            'name' => ucfirst($role).' User',
            'email' => uniqid($role.'-', true).'@example.com',
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function lead(User $sales, string $phone): Lead
    {
        return Lead::create([
            'name' => 'Test Lead',
            'phone' => $phone,
            'source' => 'website',
            'status' => 'followup',
            'priority' => 'warm',
            'assigned_to' => $sales->id,
            'created_by' => $sales->id,
        ]);
    }

    private function followup(Lead $lead, User $user): Followup
    {
        return Followup::create([
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'note' => 'Follow up today',
            'next_followup_date' => today(),
            'status' => 'pending',
        ]);
    }
}
