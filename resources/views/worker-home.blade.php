<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name', 'NaraboxTV Worker') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; max-width: 40rem; margin: 4rem auto; padding: 0 1rem; }
        h1 { font-size: 1.5rem; }
        a { color: #2563eb; }
    </style>
</head>
<body>
    <h1>{{ config('app.name', 'NaraboxTV Worker') }}</h1>
    <p>Media processing worker (transcode, probe, sync).</p>
    <ul>
        <li><a href="{{ url('/admin') }}">Filament Admin</a></li>
        <li><a href="{{ url('/horizon') }}">Horizon</a></li>
        <li><a href="{{ url('/up') }}">Health check</a></li>
    </ul>
</body>
</html>
