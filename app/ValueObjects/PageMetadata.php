<?php

namespace App\ValueObjects;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Server rendered SEO metadata for the current request.
 *
 * The application is client rendered: config/inertia.php enables SSR but no
 * bundle is built, so anything the React <Head> emits is invisible to crawlers
 * and social scrapers. These values are printed by partials/seo.blade.php into
 * the initial HTML, before any JavaScript runs.
 *
 * @phpstan-type SeoImage array{url: string, width: int, height: int, alt: string}
 * @phpstan-type SeoGraph array{'@context': string, '@graph': list<array<string, mixed>>}
 */
final readonly class PageMetadata
{
    /**
     * @param  SeoImage|null  $image
     * @param  SeoGraph|null  $structuredData
     */
    private function __construct(
        public string $title,
        public string $description,
        public string $canonical,
        public string $siteName,
        public string $locale,
        public string $robots,
        public ?array $image,
        public ?array $structuredData,
    ) {}

    /**
     * Build the metadata for the route currently being rendered.
     */
    public static function forCurrentRequest(): self
    {
        $meta = [...self::config('defaults'), ...self::pageConfig()];

        $origin = self::origin();
        $image = Arr::get($meta, 'image');

        return new self(
            title: (string) Arr::get($meta, 'title', ''),
            description: (string) Arr::get($meta, 'description', ''),
            canonical: self::canonicalUrl($origin),
            siteName: (string) config('app.name', 'Baseline'),
            locale: (string) Arr::get($meta, 'locale', 'en_GB'),
            robots: (string) Arr::get($meta, 'robots', 'noindex, nofollow'),
            image: is_array($image) ? [
                'url' => self::absolute($origin, (string) Arr::get($image, 'path', '')),
                'width' => (int) Arr::get($image, 'width', 0),
                'height' => (int) Arr::get($image, 'height', 0),
                'alt' => (string) Arr::get($image, 'alt', ''),
            ] : null,
            structuredData: Arr::get($meta, 'structured_data') === true
                ? self::graph($origin)
                : null,
        );
    }

    /**
     * The metadata overrides for the current route, if any.
     *
     * The pages array is fetched whole rather than through a dotted config key:
     * route names contain dots themselves, so config('seo.pages.portal.welcome')
     * would be resolved as pages -> portal -> welcome and find nothing.
     *
     * @return array<string, mixed>
     */
    private static function pageConfig(): array
    {
        $page = Arr::get(self::config('pages'), Route::currentRouteName() ?? '');

        return is_array($page) ? $page : [];
    }

    /**
     * The scheme and host every public URL is anchored to.
     *
     * Taken from configuration rather than the incoming request so that www and
     * non-www hosts, and any Host header a proxy passes through, all collapse to
     * a single canonical origin instead of splitting pages across duplicates.
     */
    private static function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /**
     * The configured origin plus the request path, query string stripped so
     * that tracking parameters never fork the canonical URL.
     */
    private static function canonicalUrl(string $origin): string
    {
        return $origin.Str::before(request()->getRequestUri(), '?');
    }

    private static function absolute(string $origin, string $path): string
    {
        return $origin.'/'.ltrim($path, '/');
    }

    /**
     * The schema.org graph for the public marketing page.
     *
     * @return SeoGraph
     */
    private static function graph(string $origin): array
    {
        $organization = self::config('organization');
        $application = self::config('application');

        $home = $origin.'/';
        $organizationId = $origin.'/#organization';
        $websiteId = $origin.'/#website';
        $organizationName = (string) Arr::get($organization, 'name', '');
        $email = (string) Arr::get($organization, 'email', '');

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'Organization',
                    '@id' => $organizationId,
                    'name' => $organizationName,
                    'legalName' => (string) Arr::get($organization, 'legal_name', ''),
                    'url' => $home,
                    'email' => $email,
                    'description' => (string) Arr::get($organization, 'description', ''),
                    'logo' => [
                        '@type' => 'ImageObject',
                        '@id' => $origin.'/#logo',
                        'url' => self::absolute($origin, (string) Arr::get($organization, 'logo_path', '')),
                        'caption' => $organizationName,
                    ],
                    'contactPoint' => [
                        [
                            '@type' => 'ContactPoint',
                            'contactType' => 'sales',
                            'email' => $email,
                            'availableLanguage' => ['English'],
                        ],
                    ],
                ],
                [
                    '@type' => 'WebSite',
                    '@id' => $websiteId,
                    'url' => $home,
                    'name' => $organizationName,
                    'description' => (string) Arr::get($organization, 'description', ''),
                    'inLanguage' => 'en',
                    'publisher' => ['@id' => $organizationId],
                ],
                [
                    '@type' => 'SoftwareApplication',
                    '@id' => $origin.'/#software',
                    'name' => (string) Arr::get($application, 'name', ''),
                    'url' => $home,
                    'applicationCategory' => (string) Arr::get($application, 'category', ''),
                    'applicationSubCategory' => (string) Arr::get($application, 'sub_category', ''),
                    'operatingSystem' => (string) Arr::get($application, 'operating_system', ''),
                    'description' => (string) Arr::get($application, 'description', ''),
                    'featureList' => Arr::get($application, 'feature_list', []),
                    'publisher' => ['@id' => $organizationId],
                    'isPartOf' => ['@id' => $websiteId],
                    'offers' => self::offers($home),
                ],
            ],
        ];
    }

    /**
     * One schema.org Offer per published plan.
     *
     * Plans without a listed price are quoted individually, so their offer
     * carries no price rather than an invented one.
     *
     * @return list<array<string, mixed>>
     */
    private static function offers(string $home): array
    {
        $currency = (string) config('seo.currency', 'EUR');

        return array_values(array_map(static function (mixed $plan) use ($home, $currency): array {
            $price = Arr::get($plan, 'price');

            $offer = [
                '@type' => 'Offer',
                'name' => (string) Arr::get($plan, 'name', ''),
                'description' => (string) Arr::get($plan, 'description', ''),
                'priceCurrency' => $currency,
                'url' => $home.'#pricing',
                'availability' => 'https://schema.org/InStock',
            ];

            if ($price === null) {
                return $offer;
            }

            return [
                ...$offer,
                'price' => (string) $price,
                'priceSpecification' => [
                    '@type' => 'UnitPriceSpecification',
                    'price' => (string) $price,
                    'priceCurrency' => $currency,
                    'unitText' => 'engagement',
                    'referenceQuantity' => [
                        '@type' => 'QuantitativeValue',
                        'value' => 1,
                        'unitCode' => 'MON',
                    ],
                ],
            ];
        }, self::config('plans')));
    }

    /**
     * Read a top level seo config section as an array.
     *
     * @return array<array-key, mixed>
     */
    private static function config(string $key): array
    {
        $value = config("seo.{$key}");

        return is_array($value) ? $value : [];
    }
}
