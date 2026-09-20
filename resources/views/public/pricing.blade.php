@extends('layouts.public')

@section('content')
<main id="main-content" class="pricing-page">
    <section class="pricing-hero" aria-labelledby="pricing-page-title" data-motion-zone>
        <div class="container pricing-hero-grid">
            <div class="pricing-hero-copy" data-reveal="stagger">
                <a class="text-link" href="{{ $localeUrl('/') }}" data-reveal-item>@include('public.partials.icon', ['name' => 'arrow', 'class' => 'back-arrow']) {{ __('Kembali ke landing') }}</a>
                <h1 id="pricing-page-title" data-reveal-item>{{ __('Pilih waktumu.') }}<br><span>{{ __('Buka kemungkinan.') }}</span></h1>
                <p data-reveal-item>{{ __('Enam durasi, satu ruang XSuper.ai. Mulai satu hari untuk mencoba atau pilih ritme yang menemani pekerjaanmu lebih lama.') }}</p>
                <div class="pricing-hero-actions" data-reveal-item><a class="button button-primary" href="#all-plans">{{ __('Bandingkan semua paket') }} @include('public.partials.icon', ['name' => 'arrow'])</a><a class="text-link" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Tanya kebutuhanmu') }} @include('public.partials.icon', ['name' => 'external'])</a></div>
            </div>
            <div class="orbit-stage pricing-orbit-stage" data-orbit-stage aria-label="{{ __('Enam paket XSuper.ai dalam orbit langganan') }}">
                <div class="orbit-coordinate coordinate-top" aria-hidden="true"><span>{{ __('Enam durasi.') }}</span><span>{{ __('Satu workspace.') }}</span></div>
                <div class="orbit-system">
                    <div class="orbit-ring ring-outer" aria-hidden="true"></div><div class="orbit-ring ring-inner" aria-hidden="true"></div><div class="orbit-ring ring-cross" aria-hidden="true"></div>
                    <div class="orbit-axis axis-horizontal" aria-hidden="true"></div><div class="orbit-axis axis-vertical" aria-hidden="true"></div>
                    <div class="orbit-core"><div class="core-ring" aria-hidden="true"></div><img src="/xsuper-mark.png" width="140" height="140" alt="XSuper.ai"><span>{{ __('Pilih ritmemu.') }}</span></div>
                    @foreach($plans as $plan)
                        <div class="orbit-arm arm-{{ $loop->index }}" style="--orbit-index: {{ $loop->index }}"><button type="button" class="orbit-card pricing-orbit-card {{ $plan['featured'] ? 'is-selected' : '' }}" data-pricing-ticket="{{ $plan['id'] }}" data-plan-label="{{ $plan['label'] }}" data-plan-price="{{ $plan['priceLabel'] }}" data-plan-daily="{{ $plan['perDayLabel'] }} {{ __('per hari') }}" aria-pressed="{{ $plan['featured'] ? 'true' : 'false' }}"><span>{{ $plan['label'] }}</span><strong>{{ $plan['priceLabel'] }}</strong></button></div>
                    @endforeach
                </div>
                @php($featuredPlan = collect($plans)->firstWhere('featured', true))
                <div class="orbit-caption pricing-orbit-readout"><span class="orbit-selector-mark" aria-hidden="true"></span><div><strong id="ribbon-plan-label">{{ $featuredPlan['label'] }}</strong><p><b id="ribbon-plan-price">{{ $featuredPlan['priceLabel'] }}</b> · <small id="ribbon-plan-daily">{{ $featuredPlan['perDayLabel'] }} {{ __('per hari') }}</small></p></div><span class="orbit-hint">{{ __('Pilih untuk') }}<br>{{ __('membandingkan paket') }}</span></div>
            </div>
        </div>
    </section>

    <section class="pricing-principles" aria-labelledby="pricing-principles-title" data-motion-zone>
        <div class="container"><h2 id="pricing-principles-title" data-reveal="line">{{ __('Harga jelas.') }}<br><span>{{ __('Waktumu tetap milikmu.') }}</span></h2><div class="principle-lines" data-reveal="stagger"><article data-reveal-item>@include('public.partials.icon', ['name' => 'wallet'])<div><h3>{{ __('Bayar sesuai durasi.') }}</h3><p>{{ __('Tidak ada langganan berulang otomatis pada paket yang ditampilkan.') }}</p></div></article><article data-reveal-item>@include('public.partials.icon', ['name' => 'shield'])<div><h3>{{ __('Konfirmasi sebelum membeli.') }}</h3><p>{{ __('Akses model, API, dan fitur mengikuti izin akun. Tanyakan kebutuhan spesifikmu terlebih dahulu.') }}</p></div></article><article data-reveal-item>@include('public.partials.icon', ['name' => 'file'])<div><h3>{{ __('Kebijakan tetap terbuka.') }}</h3><p>{{ __('Refund penuh untuk paket baru belum dipakai dalam 24 jam; kendala teknis diperiksa untuk replace atau pro-rata.') }}</p></div></article></div></div>
    </section>

    <section id="all-plans" class="pricing-catalog" aria-labelledby="pricing-catalog-title" data-motion-zone>
        <div class="container"><div class="section-heading pricing-heading-row" data-reveal="rise"><div><h2 id="pricing-catalog-title">{{ __('Semua pilihan.') }} <span>{{ __('Tanpa disembunyikan.') }}</span></h2><p>{{ __('Harga bersumber dari paket aplikasi XSuper.ai. Geser untuk membandingkan seluruh durasi dalam satu baris.') }}</p></div><div class="pricing-carousel-controls"><button type="button" data-pricing-prev aria-label="{{ __('Paket sebelumnya') }}">@include('public.partials.icon', ['name' => 'arrow'])</button><button type="button" data-pricing-next aria-label="{{ __('Paket berikutnya') }}">@include('public.partials.icon', ['name' => 'arrow'])</button></div></div><div class="pricing-carousel" data-pricing-carousel><div class="pricing-grid pricing-grid-page" data-reveal="stagger">@foreach($plans as $plan)<article class="pricing-card {{ $plan['featured'] ? 'featured' : '' }}" data-plan="{{ $plan['id'] }}" data-reveal-item><div class="plan-heading"><h3>{{ $plan['label'] }}</h3>@if($plan['featured'])<span>{{ __('Pilihan bulanan') }}</span>@else<span>{{ __(':days hari akses', ['days' => $plan['days']]) }}</span>@endif</div><p class="plan-description">{{ $plan['description'] }}</p><p class="plan-price">{{ $plan['priceLabel'] }}</p><p class="plan-daily">{{ $plan['perDayLabel'] }} {{ __('per hari') }}</p><a href="{{ $plan['checkoutUrl'] }}" class="button {{ $plan['featured'] ? 'button-primary' : 'button-outline' }}">{{ __('Pilih :label', ['label' => strtolower($plan['label'])]) }} @include('public.partials.icon', ['name' => 'arrow'])</a></article>@endforeach</div></div></div>
    </section>


    <section class="pricing-payment" aria-labelledby="pricing-payment-title" data-motion-zone><div class="container pricing-payment-grid"><div data-reveal="line"><h2 id="pricing-payment-title">{{ __('Bayar dengan cara') }}<br><span>{{ __('yang kamu kenal.') }}</span></h2><p>{{ __('QRIS, transfer bank, dan e-wallet lokal. Aktivasi atau perpanjangan dilakukan setelah pembayaran diverifikasi.') }}</p><a class="text-link" href="{{ $localeUrl('/#payment') }}">{{ __('Lihat alur pembayaran') }} @include('public.partials.icon', ['name' => 'arrow'])</a></div><div class="pricing-payment-rail" data-reveal="wipe">@foreach($site['payments'] as $method)<span><img src="{{ $method['logo'] }}" width="95" height="32" alt="{{ $method['name'] }}" loading="lazy"><small>{{ $method['name'] }}</small></span>@endforeach</div></div></section>

    <section class="pricing-final" data-motion-zone><div class="closing-orbit orbit-close-one" aria-hidden="true"></div><div class="closing-orbit orbit-close-two" aria-hidden="true"></div><div class="container" data-reveal="line"><img src="/xsuper-mark.png" width="54" height="54" alt="XSuper.ai" loading="lazy"><h2>{{ __('Mulai kecil.') }}<br><span>{{ __('Biarkan idemu tumbuh.') }}</span></h2><p>{{ __('Belum yakin paket mana? Ceritakan cara kerjamu—tim XSuper.ai membantu memilih tanpa memaksakan durasi.') }}</p><div><a class="button button-light" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Bicara dengan tim') }} @include('public.partials.icon', ['name' => 'external'])</a><a class="text-link" href="{{ $localeUrl('/refund-policy') }}">{{ __('Baca Refund Policy') }} @include('public.partials.icon', ['name' => 'arrow'])</a></div></div></section>
</main>
@endsection
