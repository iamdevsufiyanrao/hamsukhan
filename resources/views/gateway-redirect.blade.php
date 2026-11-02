<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Redirecting to {{ $gateway }}…</title>
    <style>body{font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#1A1714;background:#FAF8F5}main{text-align:center;padding:24px}button{margin-top:14px;padding:10px 18px;border:0;border-radius:10px;background:#E8590C;color:#fff;font-weight:700;cursor:pointer}</style>
</head>
<body>
    <main>
        <p>Taking you to {{ $gateway }} to complete the payment…</p>
        <form id="gw" method="POST" action="{{ $endpoint }}">
            @foreach ($fields as $name => $value)
                <input type="hidden" name="{{ $name }}" value="{{ $value }}">
            @endforeach
            <noscript><button type="submit">Continue to {{ $gateway }}</button></noscript>
        </form>
    </main>
    <script>document.getElementById('gw').submit();</script>
</body>
</html>
