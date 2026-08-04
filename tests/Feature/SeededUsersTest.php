<?php

use App\Enums\UserRole;
use App\Models\Organization;
use App\Models\User;

test('seeding yields one organization with one user per role', function () {
    $this->seed();

    $seededRoles = User::all()->map(fn (User $user): string => $user->role->value);

    expect(Organization::count())->toBe(1)
        ->and(User::count())->toBe(5)
        ->and($seededRoles->sort()->values()->all())
        ->toBe(collect(UserRole::cases())->map(fn (UserRole $role): string => $role->value)->sort()->values()->all());
});

test('every seeded user can log in', function (string $email) {
    $this->seed();

    $this->post(route('login.store'), [
        'email' => $email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->assertAuthenticated();
})->with([
    'owner@baseline.test',
    'delivery_manager@baseline.test',
    'commercial_manager@baseline.test',
    'member@baseline.test',
    'portfolio_viewer@baseline.test',
]);
