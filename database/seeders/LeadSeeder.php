<?php

namespace Database\Seeders;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Seeder;

class LeadSeeder extends Seeder
{
    public function run(): void
    {
        $admin  = User::where('email', 'admin@crm.com')->first();
        $sales1 = User::where('email', 'sales1@crm.com')->first();
        $sales2 = User::where('email', 'sales2@crm.com')->first();

        $leads = [
            ['name' => 'Rajesh Kumar',    'phone' => '9811001001', 'email' => 'rajesh@example.com',   'company' => 'Tech Solutions',    'source' => 'website',   'status' => 'new',           'priority' => 'hot'],
            ['name' => 'Priya Sharma',    'phone' => '9811001002', 'email' => 'priya@example.com',    'company' => 'Digital Minds',     'source' => 'referral',  'status' => 'interested',    'priority' => 'warm'],
            ['name' => 'Anil Verma',      'phone' => '9811001003', 'email' => 'anil@example.com',     'company' => 'Global Traders',    'source' => 'social',    'status' => 'followup',      'priority' => 'hot'],
            ['name' => 'Sunita Patel',    'phone' => '9811001004', 'email' => 'sunita@example.com',   'company' => 'Sunrise Exports',   'source' => 'cold_call', 'status' => 'converted',     'priority' => 'warm'],
            ['name' => 'Manoj Singh',     'phone' => '9811001005', 'email' => 'manoj@example.com',    'company' => 'Singh Enterprises', 'source' => 'website',   'status' => 'closed',        'priority' => 'cold'],
            ['name' => 'Deepa Nair',      'phone' => '9811001006', 'email' => 'deepa@example.com',    'company' => 'Nair & Co',         'source' => 'referral',  'status' => 'not_interested','priority' => 'cold'],
            ['name' => 'Vikram Joshi',    'phone' => '9811001007', 'email' => 'vikram@example.com',   'company' => 'Joshi Pharma',      'source' => 'social',    'status' => 'new',           'priority' => 'hot'],
            ['name' => 'Kavita Reddy',    'phone' => '9811001008', 'email' => 'kavita@example.com',   'company' => 'Reddy Builders',    'source' => 'website',   'status' => 'interested',    'priority' => 'warm'],
            ['name' => 'Suresh Gupta',    'phone' => '9811001009', 'email' => 'suresh@example.com',   'company' => 'Gupta Auto',        'source' => 'cold_call', 'status' => 'followup',      'priority' => 'hot'],
            ['name' => 'Anita Mishra',    'phone' => '9811001010', 'email' => 'anita@example.com',    'company' => 'Mishra Textiles',   'source' => 'referral',  'status' => 'new',           'priority' => 'warm'],
            ['name' => 'Rahul Bose',      'phone' => '9811001011', 'email' => 'rahul@example.com',    'company' => 'Bose Electronics',  'source' => 'website',   'status' => 'interested',    'priority' => 'hot'],
            ['name' => 'Pooja Mehta',     'phone' => '9811001012', 'email' => 'pooja@example.com',    'company' => 'Mehta Jewels',      'source' => 'social',    'status' => 'followup',      'priority' => 'warm'],
            ['name' => 'Dinesh Rao',      'phone' => '9811001013', 'email' => 'dinesh@example.com',   'company' => 'Rao Logistics',     'source' => 'cold_call', 'status' => 'converted',     'priority' => 'hot'],
            ['name' => 'Meena Kapoor',    'phone' => '9811001014', 'email' => 'meena@example.com',    'company' => 'Kapoor Fashion',    'source' => 'referral',  'status' => 'new',           'priority' => 'cold'],
            ['name' => 'Ravi Pandey',     'phone' => '9811001015', 'email' => 'ravi@example.com',     'company' => 'Pandey Constructions','source' => 'website', 'status' => 'closed',        'priority' => 'warm'],
        ];

        // Assign first 10 to sales1, last 5 to sales2
        foreach ($leads as $i => $data) {
            $assignedTo = $i < 10 ? $sales1->id : $sales2->id;
            Lead::create(array_merge($data, [
                'assigned_to' => $assignedTo,
                'created_by'  => $admin->id,
                'address'     => fake()->address(),
                'remarks'     => fake()->sentence(),
            ]));
        }
    }
}
