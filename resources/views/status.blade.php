<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Status – Faden</title>
</head>
<body style="margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif">
    <main style="min-height:100vh;display:grid;place-items:center;padding:24px">
        <section style="max-width:620px;background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:32px">
            <p style="margin:0;color:#2563eb;font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.08em">Faden Status</p>
            <h1 style="margin:12px 0;font-size:30px">{{ $active ? __('Wartung läuft', [], $locale) : __('Alle Systeme betriebsbereit', [], $locale) }}</h1>
            @if ($active)
                <p style="line-height:1.65;color:#475569">{{ $message }}</p>
                @if ($expectedEndAt)
                    <p style="font-size:14px;color:#64748b">{{ __('Voraussichtliches Ende:', [], $locale) }} {{ $expectedEndAt }}</p>
                @endif
            @endif
        </section>
    </main>
</body>
</html>
