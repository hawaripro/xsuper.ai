<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltrAI — Workspace AI</title>
    <meta name="description" content="Masuk ke akun UltrAI untuk menggunakan workspace, Chat AI, dan fitur sesuai akses akunmu.">
    <meta name="author" content="UltrAI">
    <meta name="robots" content="noindex, follow">
    <meta property="og:title" content="UltrAI — Workspace AI">
    <meta property="og:description" content="Workspace dan akun pribadi UltrAI.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ rtrim(config('marketing.url'), '/') . '/' . request()->path() }}">
    <meta property="og:image" content="https://ultrai.id/og-image.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:site_name" content="UltrAI">
    <meta property="og:locale" content="id_ID">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="UltrAI — Workspace AI">
    <meta name="twitter:description" content="Workspace dan akun pribadi UltrAI.">
    <meta name="twitter:image" content="https://ultrai.id/og-image.png">
    <link rel="canonical" href="{{ rtrim(config('marketing.url'), '/') . '/' . request()->path() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="/ultr-icons.png">
    <link rel="apple-touch-icon" href="/ultr-icons.png">
    <link rel="shortcut icon" type="image/png" href="/ultr-icons.png">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])

    {{-- Umami Analytics --}}
    @if(config('services.umami.id'))
    <script defer src="{{ config('services.umami.url', 'https://cloud.umami.is/script.js') }}" data-website-id="{{ config('services.umami.id') }}"></script>
    @endif
</head>
<body class="bg-white" style="font-family: 'DM Sans', ui-sans-serif, system-ui, sans-serif;">
    <div id="app"></div>

</body>
</html>
