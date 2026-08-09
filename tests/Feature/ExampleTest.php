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

test('the landing page only presents supported banner claims', function () {
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));
    $orderedBannerStatements = <<<'TS'
const bannerStatements = [
    'MORE PROFIT',
    'CONTROLLED SCOPE',
    'FASTER APPROVALS',
    'FEWER DISPUTES',
];
TS;

    expect($landingPage)
        ->toContain(
            $orderedBannerStatements,
            'font-display text-[18px] font-bold',
            'lg:text-[20px]',
            'md:grid-cols-2',
            'lg:grid-cols-4',
            'size-2.5 shrink-0 rounded-full bg-moss',
        )
        ->not->toContain(
            'const heroStats',
            'RUNNING ON BASELINE',
            '120+ DELIVERY TEAMS',
            'NO SCOPE CREEP',
            'LESS DISCUSSIONS',
        );
});

test('the landing page uses mobile-first responsive layouts', function () {
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));

    expect($landingPage)
        ->not->toContain('min-w-[1100px]')
        ->toContain(
            'overflow-x-hidden',
            'text-[44px] leading-none font-bold tracking-[-.03em] sm:text-[56px] lg:text-[64px]',
            'grid-cols-1 border-t-2 border-ink sm:mt-9 md:grid-cols-3',
            'grid-cols-1 gap-3 sm:mt-9 md:grid-cols-2 lg:grid-cols-4',
            'flex flex-col border-2 border-ink bg-white lg:flex-row',
        );
});

test('the landing page uses the margin protection call to action', function () {
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));

    expect(Str::substrCount($landingPage, 'PROTECT YOUR MARGIN →'))->toBe(2)
        ->and($landingPage)->not->toContain('SEE YOUR POSITION →');
});

test('the landing page introduction avoids unsupported performance claims', function () {
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));

    expect($landingPage)
        ->toContain(
            'Your delivery tools track the work. Baseline tracks',
            'the agreement: scope, changes, approvals, delays,',
            'and every commercial decision between them.',
        )
        ->not->toContain('Agencies lose 4–9 margin points');
});

test('the landing page contains no em dashes', function () {
    $landingPage = File::get(resource_path('js/pages/welcome.tsx'));

    expect($landingPage)->not->toContain('—');
});
