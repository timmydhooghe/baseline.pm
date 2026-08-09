{{--
    Server rendered SEO metadata.

    The application is client rendered, so nothing the React <Head> emits ever
    reaches a crawler or a social scraper. Everything a machine needs to read
    has to be printed here, in the initial HTML.

    These tags sit outside <x-inertia::head> deliberately. That component drops
    its slot entirely as soon as the SSR gateway returns a response, which
    happens the moment anyone adds resources/js/ssr.tsx. Nothing here carries a
    data-inertia attribute, so Inertia's client head manager leaves it alone: it
    only removes or replaces elements it owns.
--}}
<meta name="description" content="{{ $seo->description }}">
<meta name="robots" content="{{ $seo->robots }}">
<link rel="canonical" href="{{ $seo->canonical }}">

<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $seo->siteName }}">
<meta property="og:locale" content="{{ $seo->locale }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:url" content="{{ $seo->canonical }}">
@if ($seo->image !== null)
    <meta property="og:image" content="{{ $seo->image['url'] }}">
    <meta property="og:image:secure_url" content="{{ $seo->image['url'] }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="{{ $seo->image['width'] }}">
    <meta property="og:image:height" content="{{ $seo->image['height'] }}">
    <meta property="og:image:alt" content="{{ $seo->image['alt'] }}">
@endif

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
@if ($seo->image !== null)
    <meta name="twitter:image" content="{{ $seo->image['url'] }}">
    <meta name="twitter:image:alt" content="{{ $seo->image['alt'] }}">
@endif

@if ($seo->structuredData !== null)
    {{--
        Blade's default {{ }} escaping would turn every quote in the graph into
        &quot; and leave a script body that is no longer valid JSON. @json emits
        a bare json_encode instead, and JSON_HEX_TAG encodes < as < so a
        </script> breakout stays impossible without breaking json_decode.
    --}}
    <script type="application/ld+json">@json($seo->structuredData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)</script>
@endif
