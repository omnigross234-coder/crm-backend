<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name'     => 'Admin User',
            'email'    => 'admin@crm.com',
            'password' => Hash::make('password123'),
            'role'     => 'admin',
            'phone'    => '9000000001',
            'status'   => 'active',
        ]);

        User::create([
            'name'     => 'Sales One',
            'email'    => 'sales1@crm.com',
            'password' => Hash::make('password123'),
            'role'     => 'sales',
            'phone'    => '9000000002',
            'status'   => 'active',
        ]);

        User::create([
            'name'     => 'Sales Two',
            'email'    => 'sales2@crm.com',
            'password' => Hash::make('password123'),
            'role'     => 'sales',
            'phone'    => '9000000003',
            'status'   => 'active',
        ]);
    }
}
