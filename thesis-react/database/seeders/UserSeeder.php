<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Admin User
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@hplus.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'admin',
            'phone' => '+639171234567',
            'status' => 'active',
        ]);

        // Receptionist Users
        User::create([
            'name' => 'Cristine Abella',
            'email' => 'receptionist@hplus.com',
            'email_verified_at' => now(),
            'password' => Hash::make('password'),
            'role' => 'receptionist',
            'phone' => '+639201234567',
            'status' => 'active',
        ]);
    }
}