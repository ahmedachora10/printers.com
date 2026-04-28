<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Branch;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {

    
        $branch = Branch::first();

        $users = [
            ['name' => 'Admin User', 'username' => 'admin', 'email' => 'admin@example.com', 'role' => 'super-admin'],
            ['name' => 'Branch Manager User', 'username' => 'branchmanager', 'email' => 'branch-manager@example.com', 'role' => 'branch-admin'],
            ['name' => 'Accountant User', 'username' => 'accountant', 'email' => 'accountant@example.com', 'role' => 'accountant'],
            ['name' => 'Employee User', 'username' => 'employee', 'email' => 'employee@example.com', 'role' => 'employee', 'branch_id' => $branch->id],
            ['name' => 'Agent User', 'username' => 'agent', 'email' => 'agent@example.com', 'role' => 'agent'],
        ];

        foreach ($users as $user) {
            $newUser = User::factory()->create([
                'email' => $user['email'],
                'name' => $user['name'],
                'username' => $user['username'],
                'branch_id' => $user['branch_id'] ?? null,
            ]);

            $newUser->addRole($user['role']);
        }
     
    }
}
