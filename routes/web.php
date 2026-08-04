<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EngagementController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\StakeholderController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('engagements', [EngagementController::class, 'index'])->name('engagements.index');
    Route::post('engagements', [EngagementController::class, 'store'])->name('engagements.store');
    Route::get('engagements/{engagement}', [EngagementController::class, 'show'])->name('engagements.show');
    Route::post('engagements/{engagement}/transition', [EngagementController::class, 'transition'])->name('engagements.transition');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    Route::post('customers/{customer}/stakeholders', [StakeholderController::class, 'store'])->name('customers.stakeholders.store');
    Route::patch('stakeholders/{stakeholder}', [StakeholderController::class, 'update'])->name('stakeholders.update');
    Route::delete('stakeholders/{stakeholder}', [StakeholderController::class, 'destroy'])->name('stakeholders.destroy');

    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::patch('organization/members/{member}', [MemberController::class, 'update'])->name('organization.members.update');
    Route::delete('organization/members/{member}', [MemberController::class, 'destroy'])->name('organization.members.destroy');
    Route::post('organization/invitations', [InvitationController::class, 'store'])->name('organization.invitations.store');
    Route::delete('organization/invitations/{invitation}', [InvitationController::class, 'destroy'])->name('organization.invitations.destroy');
});

/*
 * Invitation acceptance runs as a guest and is authenticated by the emailed
 * token — the invitation is resolved by token, never by tenant scope.
 */
Route::middleware('guest')->group(function (): void {
    Route::get('invitations/{token}', [AcceptInvitationController::class, 'show'])->name('invitations.show');
    Route::post('invitations/{token}', [AcceptInvitationController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('invitations.store');
});

/*
 * Stakeholder portal. Protected portal routes will use the `stakeholder`
 * guard (magic-link / signed-URL login) once the portal work lands.
 */
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::inertia('/', 'portal/welcome')->name('welcome');
});

require __DIR__.'/settings.php';
