<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ config('app.name', 'PenChat') }}</title>

        @php
            // One card for every share. The app is behind a sign-in, so the
            // only page a crawler ever reaches is the login screen — there is
            // no per-page content to vary, and a static image needs no runtime.
            $shareTitle = 'PenChat';
            $shareText = 'A small, private chat. Sign in with a code sent to your email.';
            // `?v=` is the only way to ever change this picture. WhatsApp caches
            // a preview for weeks and will not re-fetch the same URL, so bump
            // the number when the image changes or nobody will see the new one.
            $shareImage = asset('image/og-cover.png').'?v=1';
        @endphp

        <meta name="description" content="{{ $shareText }}">

        {{--
            Absolute URLs, and `asset()` gives them because APP_URL is set —
            WhatsApp discards a relative og:image without a word.

            The width and height are not decoration either: given them,
            WhatsApp renders the large 1.91:1 card, and without them it falls
            back to a small square thumbnail cropped from the middle. The
            artwork is laid out to survive that crop anyway, because the
            fallback still happens on older clients.
        --}}
        <meta property="og:type" content="website">
        <meta property="og:site_name" content="PenChat">
        <meta property="og:url" content="{{ config('app.url') }}">
        <meta property="og:title" content="{{ $shareTitle }}">
        <meta property="og:description" content="{{ $shareText }}">
        <meta property="og:image" content="{{ $shareImage }}">
        <meta property="og:image:type" content="image/png">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta property="og:image:alt" content="Two chat bubbles under the PenChat name">

        {{-- Read by X, Telegram, Slack and Discord; three tags for four clients. --}}
        <meta name="twitter:card" content="summary_large_image">
        <meta name="twitter:title" content="{{ $shareTitle }}">
        <meta name="twitter:description" content="{{ $shareText }}">
        <meta name="twitter:image" content="{{ $shareImage }}">

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
