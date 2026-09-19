<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $page['title'] }}</title>
    <meta name="description" content="{{ $page['description'] }}">
    <meta name="author" content="XSuper.ai">
    <meta name="robots" content="{{ $page['robots'] }}">
    <meta name="theme-color" content="#f8faff">
    <link rel="canonical" href="{{ $page['canonical'] }}">
    @foreach($page['alternates'] as $language => $url)
        <link rel="alternate" hreflang="{{ $language }}" href="{{ $url }}">
    @endforeach
    <meta property="og:title" content="{{ $page['title'] }}">
    <meta property="og:description" content="{{ $page['description'] }}">
    <meta property="og:type" content="{{ $page['type'] === 'policy' ? 'article' : 'website' }}">
    <meta property="og:url" content="{{ $page['canonical'] }}">
    <meta property="og:image" content="{{ $site['url'] }}/xsuper-logo.png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:type" content="image/png">
    <meta property="og:site_name" content="XSuper.ai">
    <meta property="og:locale" content="{{ $page['ogLocale'] }}">
    <meta property="og:locale:alternate" content="{{ $page['alternateLocale'] }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="{{ $page['title'] }}">
    <meta name="twitter:description" content="{{ $page['description'] }}">
    <meta name="twitter:image" content="{{ $site['url'] }}/xsuper-logo.png">
    <link rel="icon" type="image/png" href="/xsuper-icon.png">
    <link rel="apple-touch-icon" href="/xsuper-icon.png">
    <script>try{if(localStorage.getItem('xsuper-theme')==='dark')document.documentElement.classList.add('dark')}catch(e){}</script>
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
    @if(config('services.umami.id'))
        <script defer src="{{ config('services.umami.url', 'https://cloud.umami.is/script.js') }}" data-website-id="{{ config('services.umami.id') }}"></script>
    @endif
    @if(!empty($structuredData))
        <script type="application/ld+json">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) !!}</script>
    @endif
</head>
<body class="public-site {{ $page['type'] === 'policy' ? 'policy-site' : 'landing-site' }}" data-motion="paused" data-copy-success="{{ __('Kode berhasil disalin.') }}" data-copy-failure="{{ __('Penyalinan otomatis tidak tersedia. Pilih teks kode lalu salin secara manual.') }}">
    @include('public.partials.header')
    @if(!empty($site['announcement']))
        <aside class="site-announcement" role="status" data-level="{{ $site['announcement']['level'] ?? 'info' }}" aria-label="{{ $site['announcement']['message'] }}">
            <div class="site-announcement-track">
                @foreach([false, true] as $duplicate)
                    <span class="site-announcement-copy" @if($duplicate) aria-hidden="true" @endif>
                        <strong>{{ $site['announcement']['message'] }}</strong>
                        @if(!empty($site['announcement']['action']))
                            <a href="{{ $site['announcement']['action']['url'] }}">{{ $site['announcement']['action']['label'] }}</a>
                        @endif
                        <span aria-hidden="true">•</span>
                    </span>
                @endforeach
            </div>
        </aside>
    @endif
    @yield('content')
    @include('public.partials.footer')
</body>
</html>
