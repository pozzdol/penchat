<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'PenChat') }}</title>

        {{-- The icon lockup carries its own fills, so it needs no theme handling. --}}
        <link rel="icon" href="{{ asset('image/logo/logo-icon.svg') }}" type="image/svg+xml">
        <link rel="apple-touch-icon" href="{{ asset('image/logo/logo-icon.svg') }}">

        @viteReactRefresh
        @vite(['resources/js/app.tsx', 'resources/css/app.css'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
