<?php

namespace App\Http\Controllers;

use App\Http\Requests\RateCard\StoreRateCardVersionRequest;
use App\Models\RateCardRole;
use App\Models\RateCardVersion;
use App\Models\User;
use App\ValueObjects\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RateCardController extends Controller
{
    /**
     * Show the rate card: the current version and the full version history.
     * Rates are internal-only and never rendered outside this authenticated,
     * manager-gated page.
     */
    public function show(Request $request): Response
    {
        Gate::authorize('viewAny', RateCardVersion::class);

        return Inertia::render('organization/rate-card', [
            'versions' => RateCardVersion::query()
                ->with(['roles', 'createdBy'])
                ->orderByDesc('version')
                ->get()
                ->map(fn (RateCardVersion $version): array => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'publishedAt' => $version->created_at?->toFormattedDateString(),
                    'publishedBy' => $version->createdBy?->name,
                    'roles' => $version->roles
                        ->map(fn (RateCardRole $role): array => [
                            'id' => $role->id,
                            'name' => $role->name,
                            'costPerDay' => $role->cost_per_day->toArray(),
                            'sellPerDay' => $role->sell_per_day->toArray(),
                        ])
                        ->values(),
                ]),
            'can' => [
                'manage' => $request->user()?->can('create', RateCardVersion::class) ?? false,
            ],
        ]);
    }

    /**
     * Publish the next rate card version from the submitted role rates.
     */
    public function store(StoreRateCardVersionRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        /** @var list<array{name: string, cost_per_day: string, sell_per_day: string}> $roles */
        $roles = $request->validated('roles');

        $version = $user->organization->publishRateCardVersion(
            array_map(fn (array $role): array => [
                'name' => $role['name'],
                'cost_per_day' => self::eurosToMoney($role['cost_per_day']),
                'sell_per_day' => self::eurosToMoney($role['sell_per_day']),
            ], $roles),
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate card v:version published.', [
            'version' => $version->version,
        ])]);

        return to_route('organization.rate-card.show');
    }

    /**
     * Convert a validated decimal euro amount (e.g. "780.50") to Money.
     */
    private static function eurosToMoney(string|int|float $euros): Money
    {
        return Money::fromCents((int) round((float) $euros * 100));
    }
}
