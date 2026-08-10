<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the current organization from the authenticated user and stores it
 * in Context, where the OrganizationScope and BelongsToOrganization trait
 * read it. Context propagates to queued jobs, so tenant scoping holds there too.
 *
 * Stakeholders authenticate on their own guard, so portal routes resolve the
 * organization from the stakeholder session — and prefer it over an internal
 * session that may coexist in the same browser, so the portal is always
 * scoped to the customer's side.
 */
class SetCurrentOrganization
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->routeIs('portal.*')
            ? ($request->user('stakeholder') ?? $request->user())
            : ($request->user() ?? $request->user('stakeholder'));

        if ($user !== null) {
            Context::add('organization_id', $user->organization_id);
        }

        return $next($request);
    }
}
