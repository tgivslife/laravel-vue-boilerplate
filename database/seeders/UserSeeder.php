<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::withTrashed()->firstWhere('email', 'admin@mail.com') ?? User::factory()->create([
            'first_name' => 'Admin',
            'last_name' => 'User',
            'email' => 'admin@mail.com',
            'password' => 'admin1234',
        ]);
        $admin->assignRole('admin');

        User::withTrashed()->firstWhere('email', 'user@mail.com') ?? User::factory()->create([
            'first_name' => 'Regular',
            'last_name' => 'User',
            'email' => 'user@mail.com',
            'password' => 'user1234',
        ]);
    }
}
