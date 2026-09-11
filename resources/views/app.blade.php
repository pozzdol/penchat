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
            One tag, no media query: the pair that used to sit here followed
            the system and would now contradict a person who chose a theme,
            leaving a light status bar above a dark page. The script below
            owns its value, as it owns the class on <html>.
        --}}
        <meta name="theme-color" content="#f2f3f6">

        {{--
            Resolve the theme before the first paint.

            Inline and blocking on purpose. Anything deferred — a module, a
            React effect — runs after the browser has already painted, and the
            reader sees the wrong theme flash to the right one on every single
            load. Fifteen lines in the head is the price of not doing that.

            Three states, resolved here and nowhere else: an explicit choice
            wins, and "system" falls through to the media query. The result is
            stamped as a literal class, which is why `tokens.css` needs only
            `:root` and `.dark` and no second copy of the palette.

            Wrapped in try/catch because reading localStorage *throws* in some
            contexts rather than returning null — a browser set to block site
            data, or a thumbnail capture. A theme is not worth a blank page.

            The key is duplicated in `use-theme.ts`; a test asserts the two
            still agree, because a silent disagreement here reads as "my
            choice does not stick".
        --}}
        <script>
            (function () {
                var dark = false;
                try {
                    var choice = localStorage.getItem('penchat:theme');
                    dark = choice === 'dark' || (choice !== 'light' &&
                        window.matchMedia('(prefers-color-scheme: dark)').matches);
                } catch (e) { /* Light is the floor. */ }

                document.documentElement.classList.add(dark ? 'dark' : 'light');
                var tag = document.querySelector('meta[name="theme-color"]');
                if (tag) tag.setAttribute('content', dark ? '#1a1c1e' : '#f2f3f6');
            })();
        </script>

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
