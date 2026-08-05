<?php

namespace App\Http\Controllers;

use App\Http\Requests\Integrations\ConnectIntegrationRequest;
use App\Jobs\SyncIntegrationConnection;
use App\Models\Engagement;
use App\Models\IntegrationAccount;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class IntegrationConnectionController extends Controller
{
    /**
     * Connect an execution tool to the engagement through one of the
     * organization's provider accounts — or reconnect one that was
     * disconnected earlier, resyncing into the retained history (FA-7).
     */
    public function store(ConnectIntegrationRequest $request, Engagement $engagement): RedirectResponse
    {
        $validated = $request->validated();

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $account = IntegrationAccount::query()
            ->whereKey($validated['integration_account_id'])
            ->firstOrFail();

        $engagement->connectIntegration(
            $account,
            $validated['external_project_key'],
            $user,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider connected — the first sync is queued.', [
            'provider' => $account->provider->label(),
        ])]);

        return to_route('engagements.work.show', $engagement);
    }

    /**
     * Stop syncing. The connection and everything it imported stay — only
     * the link to the org account is dropped.
     */
    public function disconnect(Request $request, IntegrationConnection $connection): RedirectResponse
    {
        Gate::authorize('disconnect', $connection);

        $user = $request->user();

        if (! $user instanceof User) {
            abort(403);
        }

        $connection->disconnect($user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':provider disconnected. The imported history is retained.', [
            'provider' => $connection->provider->label(),
        ])]);

        return to_route('engagements.work.show', $connection->engagement);
    }

    /**
     * Queue a sync pass right now.
     */
    public function sync(Request $request, IntegrationConnection $connection): RedirectResponse
    {
        Gate::authorize('sync', $connection);

        SyncIntegrationConnection::dispatch($connection);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Sync queued for :provider.', [
            'provider' => $connection->provider->label(),
        ])]);

        return to_route('engagements.work.show', $connection->engagement);
    }
}
