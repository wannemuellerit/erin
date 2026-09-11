<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0f2854">
    <title>{{ $title }} – Faden</title>
    <style>
        {!! is_readable(resource_path('css/brand-tokens.css')) ? file_get_contents(resource_path('css/brand-tokens.css')) : '' !!}

        :root {
            color-scheme: light;
            font-family: Inter, "Instrument Sans", ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            color: var(--foreground, #0f172a);
            background: var(--background, #f8fafc);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-width: 320px;
        }

        .error-page {
            position: relative;
            display: grid;
            min-height: 100svh;
            place-items: center;
            overflow: hidden;
            padding: 2rem 1.25rem;
            background:
                radial-gradient(circle at 15% 15%, rgb(37 99 235 / 12%), transparent 28rem),
                radial-gradient(circle at 85% 85%, rgb(20 184 166 / 11%), transparent 26rem),
                var(--background, #f8fafc);
        }

        .error-page::before {
            position: absolute;
            inset: 0;
            content: "";
            opacity: .48;
            background-image: radial-gradient(circle at 1px 1px, rgb(148 163 184 / 24%) 1px, transparent 0);
            background-size: 24px 24px;
            mask-image: linear-gradient(to bottom, black, transparent 80%);
            pointer-events: none;
        }

        .error-card {
            position: relative;
            width: min(100%, 42rem);
            padding: clamp(2rem, 6vw, 4rem);
            text-align: center;
            border: 1px solid rgb(226 232 240 / 90%);
            border-radius: 1.75rem;
            background: rgb(255 255 255 / 92%);
            box-shadow: 0 1px 2px rgb(15 23 42 / 4%), 0 28px 80px rgb(15 23 42 / 9%);
            backdrop-filter: blur(12px);
        }

        .logo {
            display: block;
            width: 6rem;
            height: 6rem;
            margin: 0 auto;
            filter: drop-shadow(0 14px 24px rgb(37 99 235 / 20%));
        }

        .brand {
            margin: .9rem 0 0;
            color: var(--foreground, #0f172a);
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .brand-dot {
            color: var(--erin-primary, #2563eb);
        }

        h1 {
            margin: 2rem 0 0;
            color: var(--foreground, #0f172a);
            font-size: clamp(1.85rem, 5vw, 2.6rem);
            line-height: 1.12;
            letter-spacing: -.04em;
        }

        .message {
            max-width: 34rem;
            margin: 1rem auto 0;
            color: var(--muted-foreground, #475569);
            font-size: clamp(1rem, 2.5vw, 1.1rem);
            line-height: 1.75;
        }

        .home-link {
            color: var(--erin-primary, #2563eb);
            font-weight: 800;
            text-underline-offset: .2em;
            text-decoration-thickness: .12em;
        }

        .home-link:hover {
            color: var(--erin-primary-hover, #1d4ed8);
        }

        .home-link:focus-visible {
            border-radius: .25rem;
            outline: 3px solid rgb(37 99 235 / 28%);
            outline-offset: 4px;
        }

        @media (prefers-reduced-motion: no-preference) {
            .logo {
                animation: logo-in .55s cubic-bezier(.2, .8, .2, 1) both;
            }

            @keyframes logo-in {
                from {
                    opacity: 0;
                    transform: translateY(8px) scale(.94);
                }
            }
        }
    </style>
</head>
<body>
    <main class="error-page">
        <section class="error-card" aria-labelledby="error-title">
            <img class="logo" src="{{ url('/favicon.svg') }}" alt="Faden Logo">
            <p class="brand" aria-hidden="true">faden<span class="brand-dot">.</span></p>
            <h1 id="error-title">{{ $heading }}</h1>
            <p class="message">
                {{ $message }}
                @if ($showHomeLink ?? false)
                    Klicke <a class="home-link" href="{{ url('/') }}">hier</a>, um zur Startseite zurückzukehren.
                @endif
            </p>
        </section>
    </main>
</body>
</html>
