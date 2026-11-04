<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Launchpad' }}</title>
</head>
<body style="margin:0;padding:24px;background:#f5f5f4;font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;color:#1c1917;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;padding:24px;border-radius:8px;">
    @yield('body')
    @include('mail.footer')
</div>
</body>
</html>
