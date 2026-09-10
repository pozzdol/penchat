<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'PenChat') }}</title>

        {{-- The icon lockup carries its own fills, so it needs no theme handling. --}}
        <link rel="icon" href="{{ asset('image/logo/logo-icon.svg') }}" type="image/svg+xml">

        {{--
            PNG, not the SVG that used to sit here. iOS ignores an SVG for the
            home-screen icon, and this app has to be installed to the home
            screen before Safari will deliver a push at all — so the icon that
            makes the install worth doing is a hard requirement, not polish.
            It is opaque and square on purpose; iOS applies its own rounding
            and composites anything transparent onto black.
        --}}
        <link rel="apple-touch-icon" href="{{ asset('image/icons/apple-touch-icon-180.png') }}">
        <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">

        {{--
            The manifest can only carry one theme colour; this pair follows the
            palette into dark mode so the browser chrome does not sit at odds
            with the page under it.
        --}}
        <meta name="theme-color" content="#f2f3f6" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#1a1c1e" media="(prefers-color-scheme: dark)">

        {{-- The standard name, and the one iOS has honoured since long before it. --}}
        <meta name="mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-title" content="PenChat">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">

        @viteReactRefresh
        @vite(['resources/js/app.tsx', 'resources/css/app.css'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
