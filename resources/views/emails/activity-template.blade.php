<!doctype html>
<html lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;background:#f8fafc;color:#0f172a;font-family:Arial,sans-serif">
    <main style="max-width:640px;margin:0 auto;padding:32px 20px">
        <div style="background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:28px;line-height:1.6">
            {!! $bodyHtml !!}
        </div>
    </main>
</body>
</html>
