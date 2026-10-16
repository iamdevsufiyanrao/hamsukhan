@php
    $seo = config('hamsukhan.seo');
    $site = config('hamsukhan.site.name', 'Hamsukhan');
    // Only the public pages (login, sign-up…) should ever be indexed; the app itself never is.
    $index = $seo['index'] && ! auth()->check();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="application-name" content="{{ $site }}">
        <title inertia>{{ $seo['title'] ?: $site }}</title>
        <meta name="description" content="{{ $seo['description'] }}">
        @if ($seo['keywords'])<meta name="keywords" content="{{ $seo['keywords'] }}">@endif
        <meta name="robots" content="{{ $index ? 'index,follow' : 'noindex,nofollow' }}">
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="{{ $site }}">
        <meta property="og:title" content="{{ $seo['title'] ?: $site }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ url()->current() }}">
        @if ($seo['og_image'])<meta property="og:image" content="{{ $seo['og_image'] }}">
        <meta name="twitter:card" content="summary_large_image">@endif
        <link rel="canonical" href="{{ url()->current() }}">
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=manrope:400,500,600,700,800|jetbrains-mono:400,500&display=swap" rel="stylesheet">
        <script>
            try { var t = localStorage.getItem('hs-theme'); if (t) document.documentElement.dataset.theme = t; } catch (e) {}
        </script>
        @if ($seo['ga_id'])
            <script async src="https://www.googletagmanager.com/gtag/js?id={{ $seo['ga_id'] }}"></script>
            <script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag('js', new Date()); gtag('config', @json($seo['ga_id']));</script>
        @endif
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
        @inertiaHead
    </head>
    <body>
        @inertia
    </body>
</html>
