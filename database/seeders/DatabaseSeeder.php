<?php

namespace Database\Seeders;

use App\Enums\DependencyEventType;
use App\Enums\DependencyParty;
use App\Enums\EngagementStatus;
use App\Enums\EstimateUnit;
use App\Enums\Plan;
use App\Enums\RecordVisibility;
use App\Enums\RiskRating;
use App\Enums\StakeholderRole;
use App\Enums\UserRole;
use App\Enums\WorkItemState;
use App\Models\Customer;
use App\Models\Engagement;
use App\Models\Organization;
use App\Models\Stakeholder;
use App\Models\User;
use App\Models\WorkItem;
use App\Models\WorkItemWorklog;
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
            [EngagementStatus::Archived, 'Website relaunch'],
        ];

        foreach ($engagements as [$status, $name]) {
            Engagement::factory()
                ->for($organization)
                ->for($customer)
                ->status($status)
                ->create(['name' => $name]);
        }

        $active = Engagement::factory()
            ->for($organization)
            ->for($customer)
            ->status(EngagementStatus::Active)
            ->create(['name' => 'Data platform']);

        /*
         * Standalone-mode execution work on the active engagement (FA-7):
         * manual items with logged time, ready to be mapped once a baseline
         * with deliverables exists.
         */
        $workItems = [
            ['Set up ingestion pipeline', WorkItemState::Done, 3.0],
            ['Model the reporting layer', WorkItemState::InProgress, 5.0],
            ['Dashboard wireframes', WorkItemState::Todo, 2.0],
        ];

        foreach ($workItems as [$title, $state, $days]) {
            $workItem = WorkItem::factory()
                ->for($organization)
                ->for($active)
                ->inState($state)
                ->create([
                    'title' => $title,
                    'estimate_value' => $days,
                    'estimate_unit' => EstimateUnit::Days,
                ]);

            if ($state !== WorkItemState::Todo) {
                WorkItemWorklog::factory()
                    ->for($organization)
                    ->for($workItem)
                    ->create(['author_name' => 'Member']);
            }
        }

        $this->seedGovernanceLedgers($active, $owner);
    }

    /**
     * The governance ledgers on the active engagement (FA-18..FA-21): a
     * confirmed decision and the draft that would supersede it, a risk that
     * has been re-rated upward and priced, and two dependencies — one owed by
     * the customer and already late, one owed internally.
     */
    private function seedGovernanceLedgers(Engagement $engagement, ?User $owner): void
    {
        $sso = $engagement->recordDecision([
            'title' => 'SSO excluded from phase 1',
            'context' => 'The customer identity provider is Azure AD. Wiring it up is roughly three days of work and lands outside the phase 1 window.',
            'decision' => 'Single sign-on is excluded from phase 1 and revisited in phase 2.',
            'alternatives' => [
                ['option' => 'Build SSO in phase 1', 'why_not' => 'Three days we do not have before the go-live date.'],
                ['option' => 'Third-party identity broker', 'why_not' => 'Adds a vendor the customer has not approved.'],
            ],
            'participants' => [
                ['name' => 'Delivery manager', 'affiliation' => 'Baseline'],
                ['name' => 'Petra Molnar', 'affiliation' => 'Acme Industries'],
            ],
            'evidence' => [
                ['label' => 'Steering call minutes, week 4', 'url' => null],
            ],
            'impact_scope' => 'Authentication stays local for phase 1.',
            'impact_timeline_days' => -3,
            'visibility' => RecordVisibility::Shared,
            'decided_on' => today()->subWeeks(2),
            'decided_by' => $owner?->id,
        ], $owner);
        $sso->confirm($owner);

        $engagement->recordDecision([
            'title' => 'Reporting layer built on the warehouse, not the app database',
            'context' => 'Reporting queries were slowing the transactional database during the pilot.',
            'decision' => 'Reporting reads from the warehouse; the app database stays transactional.',
            'decided_on' => today()->subWeek(),
            'decided_by' => $owner?->id,
        ], $owner);

        $migration = $engagement->registerRisk([
            'title' => 'Legacy export quality blocks the migration',
            'description' => 'Two of the three sample exports failed validation on required fields.',
            'probability' => RiskRating::Low,
            'impact' => RiskRating::High,
            'mitigation' => 'Run a full dry migration in week 3 and agree a data-quality gate with the customer.',
            'owner_id' => $owner?->id,
            'visibility' => RecordVisibility::Internal,
        ], $owner);

        $role = $engagement->organization->currentRateCardVersion()?->roles->first();

        if ($role !== null) {
            $migration->syncExposures([
                ['rate_card_role_id' => $role->id, 'days' => 8],
            ], $owner);
        }

        $migration->reassess([
            'probability' => RiskRating::High,
            'impact' => RiskRating::High,
        ], $owner, 'Third export failed validation — the source data is worse than assumed.');

        $credentials = $engagement->registerDependency([
            'title' => 'Production database credentials',
            'description' => 'Read-write access for the migration job, plus a VPN account.',
            'party' => DependencyParty::Customer,
            'responsible_stakeholder_id' => Stakeholder::query()
                ->where('customer_id', $engagement->customer_id)
                ->where('role', StakeholderRole::ProjectManager)
                ->value('id'),
            'required_on' => today()->subDays(9),
            'visibility' => RecordVisibility::Shared,
        ], $owner);

        $credentials->recordEvent(DependencyEventType::Requested, [
            'channel' => 'Email',
            'note' => 'Asked Petra for the credentials and a VPN account.',
            'occurred_at' => today()->subDays(12),
        ], $owner);
        $credentials->recordEvent(DependencyEventType::Reminded, [
            'channel' => 'Steering call',
            'note' => 'Chased on the weekly call.',
            'occurred_at' => today()->subDays(5),
        ], $owner);

        $engagement->registerDependency([
            'title' => 'Architecture sign-off on the warehouse schema',
            'party' => DependencyParty::Internal,
            'responsible_user_id' => $owner?->id,
            'required_on' => today()->addWeek(),
            'visibility' => RecordVisibility::Internal,
        ], $owner);
    }
}
