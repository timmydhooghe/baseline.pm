<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Http\Requests\Organization\UpdateMemberRoleRequest;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class MemberController extends Controller
{
    /**
     * Change a member's role within the organization.
     */
    public function update(UpdateMemberRoleRequest $request, User $member): RedirectResponse
    {
        $previousRole = $member->role;
        $member->role = UserRole::from($request->validated()['role']);
        $member->save();

        AuditLog::record('member.role_changed', $member, [
            'from' => $previousRole->value,
            'to' => $member->role->value,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is now :role.', [
            'name' => $member->name,
            'role' => $member->role->label(),
        ])]);

        return to_route('organization.show');
    }

    /**
     * Remove a member from the organization.
     */
    public function destroy(User $member): RedirectResponse
    {
        Gate::authorize('delete', $member);

        AuditLog::record('member.removed', $member, [
            'name' => $member->name,
            'email' => $member->email,
            'role' => $member->role->value,
        ]);

        $member->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name has been removed from the organization.', [
            'name' => $member->name,
        ])]);

        return to_route('organization.show');
    }
}
