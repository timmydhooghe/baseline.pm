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
        $user = $request->user();

        if ($user !== null) {
            Context::add('organization_id', $user->organization_id);
        }

        return $next($request);
    }
}
