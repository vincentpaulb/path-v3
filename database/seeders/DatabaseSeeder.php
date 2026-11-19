<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->where('slug', 'admin')->first();

        User::firstOrCreate(
            ['username' => 'admin'],
            [
                'first_name' => 'System',
                'last_name' => 'Administrator',
                'email' => 'admin@example.test',
                'role' => 'Admin',
                'role_id' => $adminRole?->id,
                'province' => null,
                'password' => 'ChangeMe123!',
            ]
        );
    }
}
