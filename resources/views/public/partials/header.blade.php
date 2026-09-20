<a class="skip-link" href="#main-content">{{ __('Langsung ke konten') }}</a>
<header class="site-header">
    <div class="container header-inner">
        <a href="{{ $localeUrl('/') }}" class="brand" aria-label="{{ __('XSuper.ai, beranda') }}">
            <img src="/xsuper-mark.png" width="42" height="42" alt="{{ __('Logo XSuper.ai') }}" fetchpriority="high">
            <span>XSuper<span class="brand-ai">.ai</span></span>
        </a>
        <button id="site-menu-toggle" class="menu-toggle" type="button" aria-label="{{ __('Buka navigasi') }}" aria-expanded="false" aria-controls="site-nav" data-open-label="{{ __('Buka menu navigasi') }}" data-close-label="{{ __('Tutup menu navigasi') }}" hidden>@include('public.partials.icon', ['name' => 'menu'])</button>
        <nav id="site-nav" class="site-nav" aria-label="{{ __('Navigasi utama') }}">
            <a href="{{ $localeUrl('/models') }}">{{ __('Model AI') }}</a>
            <a href="{{ $localeUrl('/#workspace') }}">Workspace</a>
            <a href="{{ $localeUrl('/#developers') }}">Developer</a>
            <a href="{{ $localeUrl('/pricing') }}">{{ __('Harga') }}</a>
            <a href="{{ $localeUrl('/#faq') }}">FAQ</a>
            <div class="nav-actions">
                <a class="utility-toggle language-toggle" href="{{ $page['languageUrls'][$locale === 'en' ? 'id' : 'en'] }}" data-language-toggle aria-label="{{ $locale === 'en' ? __('Ganti ke bahasa Indonesia') : __('Ganti ke bahasa Inggris') }}" hreflang="{{ $locale === 'en' ? 'id' : 'en' }}">{{ $locale === 'en' ? 'ID' : 'EN' }}</a>
                <button id="theme-toggle" class="utility-toggle theme-toggle" type="button" aria-pressed="false" aria-label="{{ __('Gunakan mode gelap') }}" data-dark-label="{{ __('Gunakan mode gelap') }}" data-light-label="{{ __('Gunakan mode terang') }}" hidden>
                    <span class="theme-icon theme-icon-moon">@include('public.partials.icon', ['name' => 'moon'])</span>
                    <span class="theme-icon theme-icon-sun">@include('public.partials.icon', ['name' => 'sun'])</span>
                </button>
                @auth
                    <a class="button button-small button-dark" href="/dashboard">{{ __('Dashboard') }} @include('public.partials.icon', ['name' => 'arrow'])</a>
                @else
                    <a class="button button-small button-dark" href="/login">{{ __('Masuk') }} @include('public.partials.icon', ['name' => 'arrow'])</a>
                @endauth
            </div>
        </nav>
    </div>
</header>
