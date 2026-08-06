<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the marketing landing page renders', function () {
    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('welcome'));
});

test('public wordmarks use the brand accent', function () {
    $accentedWordmark = '<span className="text-rust">.</span>';
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));
    $authLayout = File::get(resource_path('js/layouts/auth/auth-split-layout.tsx'));

    expect(Str::substrCount($landingPage, $accentedWordmark))->toBe(2)
        ->and(Str::substrCount($authLayout, $accentedWordmark))->toBe(1);
});
