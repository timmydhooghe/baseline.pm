<?php

namespace App\Http\Controllers;

use App\Enums\IntegrationProvider;
use App\Http\Requests\Integrations\StoreIntegrationAccountRequest;
use App\Http\Requests\Integrations\UpdateIntegrationAccountRequest;
use App\Models\IntegrationAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationAccountController extends Controller
{
    /**
     * The organization's provider accounts (FA-7): the credential sets
     * engagements connect through. Managers see which exist; only the owner
     * adds, edits, rotates, or removes them.
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', IntegrationAccount::class);

        return Inertia::render('organization/integrations', [
            'accounts' => IntegrationAccount::query()
                ->with('createdBy')
                ->withCount('connections')
                ->orderBy('name')
                ->get()
                ->map(fn (IntegrationAccount $account): array => [
                    'id' => $account->id,
                    'provider' => $account->provider->value,
                    'providerLabel' => $account->provider->label(),
                    'name' => $account->name,
                    'baseUrl' => $account->base_url,
                    'inUseCount' => $account->connections_count,
                    'createdByName' => $account->createdBy?->name,
                    'createdAt' => $account->created_at?->toFormattedDateString(),
                ]),
            'providers' => collect(IntegrationProvider::cases())
                ->map(fn (IntegrationProvider $provider): array => [
                    'value' => $provider->value,
                    'label' => $provider->label(),
                ]),
            'can' => [
                'manage' => $request->user()?->can('create', IntegrationAccount::class) ?? false,
            ],
        ]);
    }

    /**
     * Add a provider account for engagements to connect through.
     */
    public function store(StoreIntegrationAccountRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $provider = IntegrationProvider::from($validated['provider']);

        $account = $user->organization->addIntegrationAccount(
            $provider,
            $validated['name'],
            $request->credentials(),
            $provider === IntegrationProvider::Jira ? $validated['base_url'] : null,
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name added — engagements can now connect through it.', [
            'name' => $account->name,
        ])]);

        return to_route('organization.integrations.index');
    }

    /**
     * Rename an account, move its site URL, or rotate its credentials.
     */
    public function update(UpdateIntegrationAccountRequest $request, IntegrationAccount $account): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validated();
        $credentials = $request->credentials();

        $account->updateDetails(
            $validated['name'],
            $validated['base_url'] ?? $account->base_url,
            $credentials,
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $credentials !== null
            ? __(':name updated and its credentials rotated.', ['name' => $account->name])
            : __(':name updated.', ['name' => $account->name])]);

        return to_route('organization.integrations.index');
    }

    /**
     * Remove an account no engagement syncs through anymore.
     */
    public function destroy(Request $request, IntegrationAccount $account): RedirectResponse
    {
        Gate::authorize('delete', $account);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $name = $account->name;
        $account->deleteAccount($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed.', ['name' => $name])]);

        return to_route('organization.integrations.index');
    }
}
