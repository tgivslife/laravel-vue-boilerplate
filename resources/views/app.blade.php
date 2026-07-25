<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description"
          content="{{ config('app.name') }} - a secure, production-ready web application platform.">

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96"/>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg"/>
    <link rel="shortcut icon" href="/favicon.ico"/>
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png"/>
    <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}"/>
    <link rel="manifest" href="/site.webmanifest"/>

    {{--
        Apply the persisted color scheme before first paint, and give  <html> the app's background colors so the moment
        between paint and the bundle mounting is not a white flash.
        The storage key and values are VueUse useColorMode() defaults (the app's dark mode), and the colors mirror
        Nuxt UI's --ui-bg for the configured neutral palette - keep both in sync if either side ever changes.

        The nonce ties the inline script to the Content-Security-Policy header (SetSecurityHeaders);
        Without it the browser refuses to run it. The style blocks stay un-nonced on purpose: style-src relies on
        'unsafe-inline', which a nonce in the directive would void per the CSP spec.
    --}}
    <script nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">
        (function () {
            try {
                var mode = localStorage.getItem('vueuse-color-scheme') || 'auto'
                var dark = mode === 'dark'
                    || (mode !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)
                document.documentElement.classList.add(dark ? 'dark' : 'light')
            } catch (e) {}
        })()
    </script>
    <style>
        html {
            background-color: #fff;
            color-scheme: light;
        }

        html.dark {
            background-color: oklch(20.5% 0 0);
            color-scheme: dark;
        }
    </style>

    {{--
        Boot spinner: covers the gap between first paint and the bundle
        mounting (download + parse + the router's initial resolution),
        which the in-app spinner cannot see because Vue is not running
        yet. app.mount('#app') replaces the container's content, so it
        disappears exactly when the app takes over. Inline CSS only -
        Tailwind is not loaded yet. The loader mirrors Spinner.vue
        (rose primary, works on both color schemes); the fade-in delay
        mirrors SPINNER_MIN_LOADING_TIME so fast boots never flash it.
        Keep the two in sync if either changes.
    --}}
    <style>
        .boot-spinner {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            opacity: 0;
            animation: boot-spinner-appear 0.2s ease 0.1s forwards;
        }

        .boot-loader {
            position: relative;
            width: 48px;
            height: 48px;
        }

        .boot-loader::before {
            content: '';
            position: absolute;
            left: 0;
            top: 60px;
            width: 48px;
            height: 5px;
            border-radius: 50%;
            background-color: rgba(244, 63, 94, 0.5);
            animation: boot-loader-shadow 0.5s linear infinite;
        }

        .boot-loader::after {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            border-radius: 4px;
            background-color: #f43f5e;
            animation: boot-loader-jump 0.5s linear infinite;
        }

        @keyframes boot-spinner-appear {
            to {
                opacity: 1;
            }
        }

        @keyframes boot-loader-jump {
            15% {
                border-bottom-right-radius: 3px;
            }
            25% {
                transform: translateY(9px) rotate(22.5deg);
            }
            50% {
                border-bottom-right-radius: 40px;
                transform: translateY(18px) scale(1, 0.9) rotate(45deg);
            }
            75% {
                transform: translateY(9px) rotate(67.5deg);
            }
            100% {
                transform: translateY(0) rotate(90deg);
            }
        }

        @keyframes boot-loader-shadow {
            0%,
            100% {
                transform: scale(1, 1);
            }
            50% {
                transform: scale(1.2, 1);
            }
        }
    </style>

    @vite(['resources/css/fonts.css'])

    <title>{{ config('app.name', 'Laravel') }}</title>

    @vite(['resources/js/main.js'])
</head>

<body>
<div id="app" class="isolate">
    <div class="boot-spinner">
        <div class="boot-loader"></div>
    </div>
</div>
</body>

</html>
