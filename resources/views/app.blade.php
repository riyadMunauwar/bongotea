{{--
    The storefront's root document. Inertia renders the page into it, on the
    server when the SSR process answers and in the browser when it does not.

    The `seo` prop is drawn here as the head's fallback, so every storefront
    response carries its title, description, canonical address and share tags
    server-side even with SSR down. With SSR up, `<x-inertia::head>` draws the
    layout's `<Head>` instead, which renders the same tags under the same
    keys; the `data-inertia` keys are what let a later client-side visit
    replace these rather than stack beside them.
--}}
@php($seo = $page['props']['seo'] ?? [])
<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2E6B45">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    <x-inertia::head>
        <title>{{ $seo['title'] ?? config('app.name') }}</title>
        @if (filled($seo['description'] ?? null))
        <meta data-inertia="description" name="description" content="{{ $seo['description'] }}">
        @endif
        @if (filled($seo['canonical'] ?? null))
        <link data-inertia="canonical" rel="canonical" href="{{ $seo['canonical'] }}">
        <meta data-inertia="og:url" property="og:url" content="{{ $seo['canonical'] }}">
        @endif
        <meta data-inertia="og:type" property="og:type" content="{{ $seo['type'] ?? 'website' }}">
        <meta data-inertia="og:site_name" property="og:site_name" content="{{ $seo['siteName'] ?? config('app.name') }}">
        <meta data-inertia="og:locale" property="og:locale" content="bn_BD">
        <meta data-inertia="og:title" property="og:title" content="{{ $seo['title'] ?? config('app.name') }}">
        @if (filled($seo['description'] ?? null))
        <meta data-inertia="og:description" property="og:description" content="{{ $seo['description'] }}">
        @endif
        @if (filled($seo['image'] ?? null))
        <meta data-inertia="og:image" property="og:image" content="{{ $seo['image'] }}">
        <meta data-inertia="twitter:card" name="twitter:card" content="summary_large_image">
        @else
        <meta data-inertia="twitter:card" name="twitter:card" content="summary">
        @endif
    </x-inertia::head>
</head>
<body>
    <x-inertia::app />
</body>
</html>
