<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ __('Wartung – Faden', [], $locale) }}</title>
</head>
<body style="margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif">
    <main style="min-height:100vh;display:grid;place-items:center;padding:24px">
        <section style="max-width:620px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:32px;box-shadow:0 12px 40px rgba(15,23,42,.08)">
            <p style="margin:0;color:#2563eb;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Faden</p>
            <h1 style="margin:12px 0;font-size:30px">{{ __('Wir sind gleich wieder da.', [], $locale) }}</h1>
            <p style="line-height:1.65;color:#475569">{{ $message ?: __('Aktuell läuft eine geplante Wartung.', [], $locale) }}</p>
            @if ($expectedEndAt)
                <p style="margin-top:20px;font-size:14px;color:#64748b">{{ __('Voraussichtliches Ende:', [], $locale) }} {{ $expectedEndAt }}</p>
            @endif
            <p style="margin-top:24px"><a href="{{ route('status') }}" style="color:#2563eb;font-weight:700">{{ __('Statusseite öffnen', [], $locale) }}</a></p>
        </section>
    </main>
</body>
</html>
