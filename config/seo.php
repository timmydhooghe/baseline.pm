<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Metadata
    |--------------------------------------------------------------------------
    |
    | Applied to every server rendered page. The per route entries below
    | override these values key by key. Indexing is off by default on purpose:
    | every route other than the ones listed under "pages" is an authenticated
    | app screen, an auth screen or a signed stakeholder link, none of which
    | belong in a search index.
    |
    | No URLs live in this file. Config files load in an undefined order, so
    | reading config('app.url') here is unreliable. Paths are relative and get
    | resolved against the configured origin at request time by PageMetadata.
    |
    */

    'defaults' => [
        'title' => 'Baseline',
        'description' => 'Baseline is the commercial system of record for agencies delivering fixed-price work.',
        'robots' => 'noindex, nofollow',
        'locale' => 'en_GB',
        'image' => [
            'path' => '/og/baseline-og.png',
            'width' => 1200,
            'height' => 630,
            'alt' => 'Baseline: fixed price, not fixed losses.',
        ],
        'structured_data' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Per Route Metadata
    |--------------------------------------------------------------------------
    |
    | Keyed by route name. Route names contain dots, so this array is read whole
    | and indexed in PHP: config('seo.pages.portal.welcome') would be resolved
    | by Arr::get as pages -> portal -> welcome and return nothing.
    |
    | The "home" title is the fully composed title. app.tsx renders titles as
    | "{title} - {appName}", so this value has to match what the client will
    | render or the browser tab flickers on hydration.
    |
    */

    'pages' => [

        'home' => [
            'title' => 'Fixed price. Not fixed losses. - Baseline',
            'description' => 'Baseline shows agencies their commercial position on fixed-price work every morning: scope creep priced, change requests signed, delays attributed.',
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
            'structured_data' => true,
        ],

        'portal.welcome' => [
            'title' => 'Stakeholder portal - Baseline',
            'description' => 'Follow scope, change requests and progress on the engagements your delivery team runs with you.',
            'robots' => 'noindex, follow',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Structured Data
    |--------------------------------------------------------------------------
    |
    | Facts only. There is deliberately no aggregateRating, review, founding
    | date or employee count here: none of those are true yet, and publishing
    | them as schema.org claims is a manual action risk.
    |
    */

    'organization' => [
        'name' => 'Baseline',
        'legal_name' => 'Baseline',
        'email' => 'hello@baseline.pm',
        'logo_path' => '/favicon.svg',
        'description' => 'Baseline is the commercial system of record for agencies delivering fixed-price work: scope, change requests, approvals, delays and margin in one place.',
    ],

    'application' => [
        'name' => 'Baseline',
        'category' => 'BusinessApplication',
        'sub_category' => 'Project Management Software',
        'operating_system' => 'Web browser',
        'description' => 'Baseline tracks the agreement behind fixed-price delivery: scope, changes, approvals, delays and every commercial decision between them.',
        'feature_list' => [
            'Scope creep detection',
            'Change requests and approvals',
            'Client portal',
            'Delay attribution',
            'Decision and audit ledger',
            'Portfolio margin view',
            'Jira and Linear integration',
        ],
    ],

    /*
    | Mirrors the pricing section of the landing page. A null price means the
    | plan is quoted rather than listed, and its offer omits price entirely.
    */

    'plans' => [
        [
            'name' => 'Solo',
            'price' => '0',
            'description' => '1 active engagement.',
        ],
        [
            'name' => 'Studio',
            'price' => '89',
            'description' => 'Up to 25 active engagements, priced per engagement per month.',
        ],
        [
            'name' => 'Firm',
            'price' => null,
            'description' => 'Unlimited engagements, SSO and DPA. Custom pricing.',
        ],
    ],

    'currency' => 'EUR',

];
