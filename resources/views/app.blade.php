<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XSuper.ai — Workspace AI</title>
    <meta name="description" content="Masuk ke akun XSuper.ai untuk menggunakan workspace, Chat AI, dan fitur sesuai akses akunmu.">
    <meta name="author" content="XSuper.ai">
    <meta name="robots" content="noindex, follow">
    <meta property="og:title" content="XSuper.ai — Workspace AI">
    <meta property="og:description" content="Workspace dan akun pribadi XSuper.ai.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ rtrim(config('marketing.url'), '/') . '/' . request()->path() }}">
    <meta property="og:image" content="https://xsuper.dev/xsuper-logo.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:site_name" content="XSuper.ai">
    <meta property="og:locale" content="id_ID">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="XSuper.ai — Workspace AI">
    <meta name="twitter:description" content="Workspace dan akun pribadi XSuper.ai.">
    <meta name="twitter:image" content="https://xsuper.dev/xsuper-logo.png">
    <link rel="canonical" href="{{ rtrim(config('marketing.url'), '/') . '/' . request()->path() }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="/xsuper-icon.png">
    <link rel="apple-touch-icon" href="/xsuper-icon.png">
    <link rel="shortcut icon" type="image/png" href="/xsuper-icon.png">
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
