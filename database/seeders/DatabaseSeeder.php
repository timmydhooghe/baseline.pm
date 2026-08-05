<?php

namespace Database\Seeders;

use App\Enums\EngagementStatus;
use App\Enums\Plan;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Stakeholder;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database: one organization with one user per
     * role, a published rate card, a customer with its stakeholders, and
     * engagements across the lifecycle.
     *
     * Every user logs in with the password "password", e.g. owner@baseline.test.
     */
    public function run(): void
    {
        $organization = Organization::factory()->create([
            'name' => 'Baseline',
            'plan' => Plan::Studio,
        ]);

        $owner = null;

        foreach (UserRole::cases() as $role) {
            $user = User::factory()
                ->for($organization)
                ->role($role)
                ->create([
                    'name' => $role->label(),
                    'email' => "{$role->value}@baseline.test",
                ]);

            if ($role === UserRole::Owner) {
                $owner = $user;
            }
        }

        $organization->publishRateCardVersion([
            ['name' => 'Delivery lead', 'cost_per_day' => Money::fromCents(52000), 'sell_per_day' => Money::fromCents(95000)],
            ['name' => 'Senior developer', 'cost_per_day' => Money::fromCents(45000), 'sell_per_day' => Money::fromCents(78000)],
            ['name' => 'Designer', 'cost_per_day' => Money::fromCents(38000), 'sell_per_day' => Money::fromCents(65000)],
        ], $owner);

        $customer = Customer::factory()
            ->for($organization)
            ->create(['name' => 'Acme Industries']);

        $stakeholders = [
            [StakeholderRole::ProjectManager, 'Petra Molnar', 'pm@acme.test'],
            [StakeholderRole::Approver, 'Anders Vik', 'approver@acme.test'],
            [StakeholderRole::Viewer, 'Vera Sloot', 'viewer@acme.test'],
        ];

        foreach ($stakeholders as [$role, $name, $email]) {
            Stakeholder::factory()
                ->for($organization)
                ->for($customer)
                ->role($role)
                ->create(['name' => $name, 'email' => $email]);
        }

        $engagements = [
            [EngagementStatus::Draft, 'ERP rollout'],
            [EngagementStatus::Active, 'Data platform'],
            [EngagementStatus::Archived, 'Website relaunch'],
        ];

        foreach ($engagements as [$status, $name]) {
            Engagement::factory()
                ->for($organization)
                ->for($customer)
                ->status($status)
                ->create(['name' => $name]);
        }
    }
}
