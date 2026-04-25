<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

        $users = [
            ['name' => 'Test User', 'email' => 'admin@example.com', 'role' => 'super-admin'],
            ['name' => 'Admin User', 'email' => 'branch-manager@example.com', 'role' => 'branch-manager'],
            ['name' => 'Accountant User', 'email' => 'accountant@example.com', 'role' => 'accountant'],
            ['name' => 'Employee User', 'email' => 'employee@example.com', 'role' => 'employee'],
            ['name' => 'Agent User', 'email' => 'agent@example.com', 'role' => 'agent'],
        ];

        foreach ($users as $user) {
            $newUser = User::factory()->create([
                'email' => $user['email'],
                'name' => $user['name']
            ]);

            $newUser->addRole($user['role']);
        }
     
    }
}
