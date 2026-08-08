<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

/**
 * The application is client rendered, so every assertion here deliberately
 * inspects the raw HTML rather than Inertia props: what a crawler or a social
 * scraper reads is exactly what the server sends before any JavaScript runs.
 */
beforeEach(function () {
    config()->set('app.url', 'https://baseline.pm');

    /*
     * Inertia posts to the Vite dev server whenever public/hot exists, which
     * would make this suite depend on whether `npm run dev` happens to be
     * running on the machine.
     */
    Inertia::disableSsr();
});

/**
 * @return array<string, mixed>
 */
function structuredData(string $html): array
{
    expect($html)->toContain('<script type="application/ld+json">');

    preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);

    return json_decode($matches[1], true, 512, JSON_THROW_ON_ERROR);
}

test('the landing page serves its description and canonical in the initial html', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="description" content="Baseline shows agencies their commercial position on fixed-price work every morning: scope creep priced, change requests signed, delays attributed.">', false)
        ->assertSee('<link rel="canonical" href="https://baseline.pm/">', false)
        ->assertSee('<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">', false)
        ->assertSee('<title>Fixed price. Not fixed losses. - Baseline</title>', false);
});

test('the canonical url ignores tracking parameters', function () {
    $response = $this->get(route('home').'/?utm_source=newsletter&utm_medium=email')
        ->assertOk()
        ->assertSee('<link rel="canonical" href="https://baseline.pm/">', false)
        ->assertSee('<meta property="og:url" content="https://baseline.pm/">', false);

    /*
     * Inertia echoes the full request URL back in its own data-page payload, so
     * the query string is asserted against the advertised URLs specifically
     * rather than the document as a whole.
     */
    preg_match_all(
        '#(?:rel="canonical" href|property="og:url" content)="([^"]+)"#',
        $response->getContent(),
        $matches,
    );

    expect($matches[1])->toHaveCount(2)
        ->each->not->toContain('?');
});

test('the landing page serves a complete open graph card', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta property="og:type" content="website">', false)
        ->assertSee('<meta property="og:site_name" content="Baseline">', false)
        ->assertSee('<meta property="og:locale" content="en_GB">', false)
        ->assertSee('<meta property="og:title" content="Fixed price. Not fixed losses. - Baseline">', false)
        ->assertSee('<meta property="og:url" content="https://baseline.pm/">', false)
        ->assertSee('<meta property="og:image" content="https://baseline.pm/og/baseline-og.png">', false)
        ->assertSee('<meta property="og:image:width" content="1200">', false)
        ->assertSee('<meta property="og:image:height" content="630">', false)
        ->assertSee('<meta property="og:image:alt" content="Baseline: fixed price, not fixed losses.">', false);
});

test('the landing page serves a large summary twitter card', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
        ->assertSee('<meta name="twitter:title" content="Fixed price. Not fixed losses. - Baseline">', false)
        ->assertSee('<meta name="twitter:description" content="Baseline shows agencies their commercial position', false)
        ->assertSee('<meta name="twitter:image" content="https://baseline.pm/og/baseline-og.png">', false);
});

test('the landing page embeds a valid schema.org graph', function () {
    $graph = structuredData($this->get(route('home'))->assertOk()->getContent());

    expect($graph['@context'])->toBe('https://schema.org')
        ->and(array_column($graph['@graph'], '@type'))
        ->toBe(['Organization', 'WebSite', 'SoftwareApplication']);
});

test('the schema.org graph resolves every url against the configured origin', function () {
    $graph = structuredData($this->get(route('home'))->getContent());

    $organization = $graph['@graph'][0];
    $software = $graph['@graph'][2];

    expect($organization['@id'])->toBe('https://baseline.pm/#organization')
        ->and($organization['logo']['url'])->toBe('https://baseline.pm/favicon.svg')
        ->and($software['publisher']['@id'])->toBe('https://baseline.pm/#organization')
        ->and($software['isPartOf']['@id'])->toBe('https://baseline.pm/#website')
        ->and($software['applicationCategory'])->toBe('BusinessApplication');
});

test('the schema.org offers mirror the published pricing', function () {
    $graph = structuredData($this->get(route('home'))->getContent());

    $offers = collect($graph['@graph'])->firstWhere('@type', 'SoftwareApplication')['offers'];

    expect(array_column($offers, 'name'))->toBe(['Solo', 'Studio', 'Firm'])
        ->and($offers[0]['price'])->toBe('0')
        ->and($offers[0]['priceCurrency'])->toBe('EUR')
        ->and($offers[1]['price'])->toBe('89')
        ->and($offers[1]['priceSpecification']['unitText'])->toBe('engagement')
        ->and($offers[2])->not->toHaveKey('price');
});

test('the schema.org graph claims no unearned trust signals', function () {
    preg_match(
        '#<script type="application/ld\+json">(.*?)</script>#s',
        $this->get(route('home'))->getContent(),
        $matches,
    );

    expect($matches[1])
        ->not->toContain('aggregateRating')
        ->not->toContain('ratingValue')
        ->not->toContain('"review"')
        ->not->toContain('numberOfEmployees')
        ->not->toContain('foundingDate');
});

test('application screens are excluded from search indexes', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
        ->assertDontSee('application/ld+json', false);
});

test('the stakeholder portal describes itself without inviting indexing', function () {
    $this->get(route('portal.welcome'))
        ->assertOk()
        ->assertSee('<meta name="robots" content="noindex, follow">', false)
        ->assertSee('<link rel="canonical" href="https://baseline.pm/portal">', false)
        ->assertSee('<title>Stakeholder portal - Baseline</title>', false);
});

test('the configured social card exists on disk', function () {
    $image = config('seo.defaults.image');

    expect(File::exists(public_path($image['path'])))->toBeTrue()
        ->and(getimagesize(public_path($image['path'])))
        ->toMatchArray([0 => $image['width'], 1 => $image['height']]);
});

test('the server rendered title matches the title the client will render', function () {
    /*
     * Inertia's head manager always replaces the server rendered <title>, so a
     * mismatch between these two shows up as a flicker on every page load.
     */
    expect(File::get(resource_path('js/pages/welcome.tsx')))
        ->toContain('<Head title="Fixed price. Not fixed losses." />')
        ->and(config('seo.pages')['home']['title'])
        ->toBe('Fixed price. Not fixed losses. - Baseline');
});

test('the landing page emits exactly one description tag', function () {
    /*
     * Inertia does not deduplicate keyless <Head> children against server
     * rendered markup, so a description left in welcome.tsx would appear twice
     * in the live DOM.
     */
    expect(substr_count($this->get(route('home'))->getContent(), 'name="description"'))->toBe(1)
        ->and(File::get(resource_path('js/pages/welcome.tsx')))->not->toContain('name="description"');
});
