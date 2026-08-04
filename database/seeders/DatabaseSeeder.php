<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: one organization with one user per role.
     *
     * Every user logs in with the password "password", e.g. owner@baseline.test.
     */
    public function run(): void
    {
        $organization = Organization::factory()->create(['name' => 'Baseline']);

        foreach (UserRole::cases() as $role) {
            User::factory()
                ->for($organization)
                ->role($role)
                ->create([
                    'name' => $role->label(),
                    'email' => "{$role->value}@baseline.test",
                ]);
        }
    }
}
