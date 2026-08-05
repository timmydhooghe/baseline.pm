<?php

use App\Http\Controllers\AcceptInvitationController;
use App\Http\Controllers\BaselineCommercialController;
use App\Http\Controllers\BaselineController;
use App\Http\Controllers\BaselineDocumentController;
use App\Http\Controllers\BaselineItemController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\EngagementController;
use App\Http\Controllers\IntegrationAccountController;
use App\Http\Controllers\IntegrationConnectionController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MemberController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\RateCardController;
use App\Http\Controllers\StakeholderController;
use App\Http\Controllers\TriageController;
use App\Http\Controllers\WorkController;
use App\Http\Controllers\WorkItemController;
use App\Http\Controllers\WorkItemLinkController;
use App\Http\Controllers\WorkItemTriageController;
use App\Http\Controllers\WorkItemWorklogController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');

    Route::get('engagements', [EngagementController::class, 'index'])->name('engagements.index');
    Route::post('engagements', [EngagementController::class, 'store'])->name('engagements.store');
    Route::get('engagements/{engagement}', [EngagementController::class, 'show'])->name('engagements.show');
    Route::post('engagements/{engagement}/transition', [EngagementController::class, 'transition'])->name('engagements.transition');

    Route::get('engagements/{engagement}/baseline', [BaselineController::class, 'show'])->name('engagements.baseline.show');
    Route::post('engagements/{engagement}/baseline', [BaselineController::class, 'store'])->name('engagements.baseline.store');
    Route::patch('baselines/{baseline}', [BaselineController::class, 'update'])->name('baselines.update');
    Route::post('baselines/{baseline}/checks/acknowledge', [BaselineController::class, 'acknowledge'])->name('baselines.checks.acknowledge');
    Route::post('baselines/{baseline}/submit', [BaselineController::class, 'submit'])->name('baselines.submit');
    Route::post('baselines/{baseline}/items', [BaselineItemController::class, 'store'])->name('baselines.items.store');
    Route::patch('baselines/{baseline}/items/{item}', [BaselineItemController::class, 'update'])->scopeBindings()->name('baselines.items.update');
    Route::delete('baselines/{baseline}/items/{item}', [BaselineItemController::class, 'destroy'])->scopeBindings()->name('baselines.items.destroy');
    Route::post('baselines/{baseline}/documents', [BaselineDocumentController::class, 'store'])->name('baselines.documents.store');
    Route::get('baselines/{baseline}/documents/{document}', [BaselineDocumentController::class, 'show'])->scopeBindings()->name('baselines.documents.show');
    Route::delete('baselines/{baseline}/documents/{document}', [BaselineDocumentController::class, 'destroy'])->scopeBindings()->name('baselines.documents.destroy');
    Route::put('baselines/{baseline}/commercials', [BaselineCommercialController::class, 'update'])->name('baselines.commercials.update');

    Route::get('engagements/{engagement}/work', [WorkController::class, 'show'])->name('engagements.work.show');
    Route::post('engagements/{engagement}/integrations', [IntegrationConnectionController::class, 'store'])->name('engagements.integrations.store');
    Route::post('integrations/{connection}/disconnect', [IntegrationConnectionController::class, 'disconnect'])->name('integrations.disconnect');
    Route::post('integrations/{connection}/sync', [IntegrationConnectionController::class, 'sync'])->name('integrations.sync');
    Route::post('engagements/{engagement}/work-items', [WorkItemController::class, 'store'])->name('engagements.work-items.store');
    Route::patch('work-items/{workItem}', [WorkItemController::class, 'update'])->name('work-items.update');
    Route::post('work-items/{workItem}/worklogs', [WorkItemWorklogController::class, 'store'])->name('work-items.worklogs.store');
    Route::post('engagements/{engagement}/work-item-links', [WorkItemLinkController::class, 'store'])->name('engagements.work-item-links.store');
    Route::delete('work-items/{workItem}/link', [WorkItemLinkController::class, 'destroy'])->name('work-items.link.destroy');
    Route::get('engagements/{engagement}/triage', [TriageController::class, 'show'])->name('engagements.triage.show');
    Route::post('work-items/{workItem}/triage', [WorkItemTriageController::class, 'store'])->name('work-items.triage.store');

    Route::get('customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->name('customers.destroy');

    Route::post('customers/{customer}/stakeholders', [StakeholderController::class, 'store'])->name('customers.stakeholders.store');
    Route::patch('stakeholders/{stakeholder}', [StakeholderController::class, 'update'])->name('stakeholders.update');
    Route::delete('stakeholders/{stakeholder}', [StakeholderController::class, 'destroy'])->name('stakeholders.destroy');

    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
    Route::get('organization/rate-card', [RateCardController::class, 'show'])->name('organization.rate-card.show');
    Route::post('organization/rate-card', [RateCardController::class, 'store'])->name('organization.rate-card.store');
    Route::get('organization/integrations', [IntegrationAccountController::class, 'index'])->name('organization.integrations.index');
    Route::post('organization/integrations', [IntegrationAccountController::class, 'store'])->name('organization.integrations.store');
    Route::patch('organization/integrations/{account}', [IntegrationAccountController::class, 'update'])->name('organization.integrations.update');
    Route::delete('organization/integrations/{account}', [IntegrationAccountController::class, 'destroy'])->name('organization.integrations.destroy');
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
