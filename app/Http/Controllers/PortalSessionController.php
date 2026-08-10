<?php

namespace App\Http\Controllers;

use App\Models\Scopes\OrganizationScope;
use App\Models\Stakeholder;
use App\Notifications\PortalLoginLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signs stakeholders in and out of the customer portal (FA-27). There is no
 * password: a stakeholder requests a link by email, and the personally
 * signed, short-lived link they receive establishes a session on the
 * separate `stakeholder` guard. The response to a link request never reveals
 * whether an address is known.
 */
class PortalSessionController extends Controller
{
    /**
     * The portal's front door: the sign-in screen, or straight through for a
     * stakeholder whose session is still alive.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user('stakeholder') !== null) {
            return redirect()->route('portal.home');
        }

        return Inertia::render('portal/login');
    }

    /**
     * Email a sign-in link to every stakeholder record behind the address —
     * resolved across organizations, never by tenant scope, because the same
     * contact may sit in several customers' stakeholder lists.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        Stakeholder::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->where('email', $validated['email'])
            ->get()
            ->each(fn (Stakeholder $stakeholder) => $stakeholder->notify(new PortalLoginLink));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('If we know this address, a sign-in link is on its way — check your inbox.'),
        ]);

        return back();
    }

    /**
     * Establish the portal session from a signed sign-in link. The
     * stakeholder is resolved by the signed parameter, never by tenant
     * scope, and the session id is regenerated against fixation.
     *
     * Deliberately a plain session, no remember-me cookie: a year-long
     * recaller from one 30-minute link would quietly outlive any shared
     * machine, and requesting a fresh link costs the stakeholder nothing.
     */
    public function consume(Request $request, string $stakeholder): RedirectResponse
    {
        $record = Stakeholder::query()
            ->withoutGlobalScope(OrganizationScope::class)
            ->findOrFail($stakeholder);

        Auth::guard('stakeholder')->login($record);

        $request->session()->regenerate();

        return redirect()->route('portal.home');
    }

    /**
     * End the portal session. Only the stakeholder guard is logged out — an
     * internal member previewing the portal keeps their own session — so the
     * session is regenerated rather than invalidated.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('stakeholder')->logout();

        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.welcome');
    }
}
