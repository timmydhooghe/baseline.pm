<?php

use App\Http\Controllers\OrganizationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
    Route::inertia('engagements', 'engagements/index')->name('engagements.index');
    Route::get('organization', [OrganizationController::class, 'show'])->name('organization.show');
});

/*
 * Stakeholder portal. Protected portal routes will use the `stakeholder`
 * guard (magic-link / signed-URL login) once WEBAPP-16 lands.
 */
Route::prefix('portal')->name('portal.')->group(function (): void {
    Route::inertia('/', 'portal/welcome')->name('welcome');
});

require __DIR__.'/settings.php';
