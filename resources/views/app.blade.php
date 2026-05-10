<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>UltrAI — AI-Powered Platform for UMKM Indonesia</title>
    <meta name="description" content="Platform AI terdepan untuk UMKM Indonesia. SMM Panel, PPOB, dan Produk Digital dengan kecerdasan buatan.">
    <meta property="og:title" content="UltrAI — AI-Powered Platform for UMKM Indonesia">
    <meta property="og:description" content="Platform AI terdepan untuk UMKM Indonesia. SMM Panel, PPOB, dan Produk Digital dengan kecerdasan buatan.">
    <meta property="og:type" content="website">
    <meta property="og:url" content="https://ultrai.id">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <link rel="icon" type="image/png" href="/ultr-icons.png">
    <link rel="apple-touch-icon" href="/ultr-icons.png">
    <link rel="shortcut icon" type="image/png" href="/ultr-icons.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])

    {{-- Umami Analytics --}}
    @if(config('services.umami.id'))
    <script defer src="{{ config('services.umami.url', 'https://cloud.umami.is/script.js') }}" data-website-id="{{ config('services.umami.id') }}"></script>
    @endif
</head>
<body class="font-['Inter'] bg-white">
    <div id="app"></div>
</body>
</html>
