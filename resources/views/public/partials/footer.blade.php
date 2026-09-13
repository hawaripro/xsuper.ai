<footer class="site-footer">
    <div class="container">
        <div class="footer-main">
            <div class="footer-brand-block"><a href="{{ $localeUrl('/') }}" class="brand" aria-label="{{ __('UltrAI, beranda') }}"><img src="/brands/ultrai/mark-96.webp" width="44" height="44" alt="{{ __('Logo UltrAI') }}" loading="lazy"><span>Ultr<span class="brand-ai">AI</span><span class="brand-period">.</span></span></a><p>{{ __('Satu ruang untuk ide.') }}<br>{{ __('Banyak cara untuk mewujudkannya.') }}</p><a class="text-link" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Bicara dengan tim UltrAI') }} @include('public.partials.icon', ['name' => 'external'])</a></div>
            <nav aria-label="{{ __('Jelajahi UltrAI') }}"><h2>{{ __('Jelajahi') }}</h2><a href="{{ $localeUrl('/models') }}">{{ __('Pilihan model') }}</a><a href="{{ $localeUrl('/#workspace') }}">Workspace AI</a><a href="{{ $localeUrl('/#developers') }}">{{ __('Untuk developer') }}</a><a href="{{ $localeUrl('/#for-you') }}">{{ __('Untuk kamu') }}</a></nav>
            <nav aria-label="{{ __('Mulai dan bantuan') }}"><h2>{{ __('Mulai & bantuan') }}</h2><a href="{{ $localeUrl('/pricing') }}">{{ __('Paket & harga') }}</a><a href="{{ $localeUrl('/#payment') }}">{{ __('Metode pembayaran') }}</a><a href="{{ $localeUrl('/#faq') }}">FAQ</a><a href="/login">{{ __('Masuk ke akun') }}</a></nav>
            <nav aria-label="{{ __('Kebijakan layanan') }}"><h2>{{ __('Transparansi') }}</h2><a href="{{ $localeUrl('/privacy-policy') }}">Privacy Policy</a><a href="{{ $localeUrl('/terms-of-service') }}">Terms of Service</a><a href="{{ $localeUrl('/refund-policy') }}">Refund Policy</a><a href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Hubungi kami') }}</a></nav>
        </div>
        <p class="footer-wordmark" aria-hidden="true">Beyond <span>ordinary.</span></p>
        <div class="footer-bottom">
            <p>&copy; {{ date('Y') }} UltrAI. {{ __('Semua hak dilindungi.') }}</p>
        </div>
    </div>
</footer>
<div id="site-status" class="site-status" role="status" aria-live="polite"></div>
