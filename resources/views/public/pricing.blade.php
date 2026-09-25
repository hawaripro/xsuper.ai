@extends('layouts.public')

@section('content')
<main id="main-content" class="pricing-page">
    <section class="pricing-hero" aria-labelledby="pricing-page-title" data-motion-zone>
        <div class="container pricing-hero-grid">
            <div class="pricing-hero-copy" data-reveal="stagger">
                <a class="text-link" href="{{ $localeUrl('/') }}" data-reveal-item>@include('public.partials.icon', ['name' => 'arrow', 'class' => 'back-arrow']) {{ __('Kembali ke landing') }}</a>
                <h1 id="pricing-page-title" data-reveal-item>{{ __('Pilih waktumu.') }}<br><span>{{ __('Buka kemungkinan.') }}</span></h1>
                <p data-reveal-item>{{ __('Bonus token media, Saldo AI, dan penyimpanan dalam satu paket. Chat, Studio & API memakai saldo/token sesuai pemakaian, bukan akses tanpa batas.') }}</p>
                <div class="pricing-hero-actions" data-reveal-item><a class="button button-primary" href="#all-plans">{{ __('Bandingkan semua paket') }} @include('public.partials.icon', ['name' => 'arrow'])</a><a class="text-link" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Tanya kebutuhanmu') }} @include('public.partials.icon', ['name' => 'external'])</a></div>
            </div>
            <div class="orbit-stage pricing-orbit-stage" data-orbit-stage aria-label="{{ __('Enam paket XSuper.ai dalam orbit langganan') }}">
                <div class="orbit-coordinate coordinate-top" aria-hidden="true"><span>{{ __('Enam durasi.') }}</span><span>{{ __('Satu workspace.') }}</span></div>
                <div class="orbit-system">
                    <div class="orbit-ring ring-outer" aria-hidden="true"></div><div class="orbit-ring ring-inner" aria-hidden="true"></div><div class="orbit-ring ring-cross" aria-hidden="true"></div>
                    <div class="orbit-axis axis-horizontal" aria-hidden="true"></div><div class="orbit-axis axis-vertical" aria-hidden="true"></div>
                    <div class="orbit-core"><div class="core-ring" aria-hidden="true"></div><img src="/xsuper-icon-v2.png" width="140" height="140" alt="XSuper.ai"><span>{{ __('Pilih ritmemu.') }}</span></div>
                    @foreach($plans as $plan)
                        <div class="orbit-arm arm-{{ $loop->index }}" style="--orbit-index: {{ $loop->index }}"><button type="button" class="orbit-card pricing-orbit-card {{ $plan['featured'] ? 'is-selected' : '' }}" data-pricing-ticket="{{ $plan['id'] }}" data-plan-label="{{ $plan['label'] }}" data-plan-price="{{ $plan['priceLabel'] }}" data-plan-daily="{{ $plan['perDayLabel'] }} {{ __('per hari') }}" aria-pressed="{{ $plan['featured'] ? 'true' : 'false' }}"><span>{{ $plan['label'] }}</span><strong>{{ $plan['priceLabel'] }}</strong></button></div>
                    @endforeach
                </div>
                @php($featuredPlan = collect($plans)->firstWhere('featured', true) ?? $plans[0])
                <div class="orbit-caption pricing-orbit-readout"><span class="orbit-selector-mark" aria-hidden="true"></span><div><strong id="ribbon-plan-label">{{ $featuredPlan['label'] }}</strong><p><b id="ribbon-plan-price">{{ $featuredPlan['priceLabel'] }}</b> · <small id="ribbon-plan-daily">{{ $featuredPlan['perDayLabel'] }} {{ __('per hari') }}</small></p></div><span class="orbit-hint">{{ __('Pilih untuk') }}<br>{{ __('membandingkan paket') }}</span></div>
            </div>
        </div>
    </section>

    <section class="pricing-principles" aria-labelledby="pricing-principles-title" data-motion-zone>
        <div class="container"><h2 id="pricing-principles-title" data-reveal="line">{{ __('Harga jelas.') }}<br><span>{{ __('Saldo tetap milikmu.') }}</span></h2><div class="principle-lines" data-reveal="stagger"><article data-reveal-item>@include('public.partials.icon', ['name' => 'wallet'])<div><h3>{{ __('Bayar sesuai pemakaian.') }}</h3><p>{{ __('Chat & API memakai Saldo AI; Studio memakai token media. Langganan adalah bonus, bukan syarat akses.') }}</p></div></article><article data-reveal-item>@include('public.partials.icon', ['name' => 'shield'])<div><h3>{{ __('Saldo tidak terkunci.') }}</h3><p>{{ __('Saat langganan berakhir, saldo dan token tetap dapat digunakan. Hanya bonus penyimpanan yang berakhir.') }}</p></div></article><article data-reveal-item>@include('public.partials.icon', ['name' => 'file'])<div><h3>{{ __('Bonus penyimpanan tidak bertumpuk.') }}</h3><p>{{ __('Paket aktif terbesar menentukan bonus penyimpanan. Upgrade penyimpanan terpisah tetap dijumlahkan.') }}</p></div></article></div></div>
    </section>

    <section id="all-plans" class="pricing-catalog" aria-labelledby="pricing-catalog-title" data-motion-zone>
        <div class="container">
            <div class="section-heading pricing-heading-row" data-reveal="rise"><div><h2 id="pricing-catalog-title">{{ __('Semua pilihan.') }} <span>{{ __('Tanpa disembunyikan.') }}</span></h2><p>{{ __('Harga dan bonus langsung dari katalog aplikasi. Bonus diberikan sekali setelah pembayaran disetujui.') }}</p></div><div class="pricing-carousel-controls"><button type="button" data-pricing-prev aria-label="{{ __('Paket sebelumnya') }}">@include('public.partials.icon', ['name' => 'arrow'])</button><button type="button" data-pricing-next aria-label="{{ __('Paket berikutnya') }}">@include('public.partials.icon', ['name' => 'arrow'])</button></div></div>
            <div class="pricing-carousel" data-pricing-carousel><div class="pricing-grid pricing-grid-page" data-reveal="stagger">
                @foreach($plans as $plan)
                    <article class="pricing-card {{ $plan['featured'] ? 'featured' : '' }}" data-plan="{{ $plan['id'] }}" data-reveal-item>
                        <div class="plan-heading"><h3>{{ $plan['label'] }}</h3><span>{{ __(':days hari langganan', ['days' => $plan['days']]) }}</span></div>
                        <p class="plan-description">{{ $plan['description'] }}</p>
                        <p class="plan-price">{{ $plan['priceLabel'] }}</p>
                        <p class="plan-daily">{{ $plan['perDayLabel'] }} {{ __('per hari') }}</p>
                        <ul>
                            <li>{{ __(':count token media', ['count' => number_format($plan['bonus_tokens'], 0, ',', $locale === 'en' ? ',' : '.')]) }}</li>
                            <li>{{ __('Saldo AI :amount', ['amount' => $plan['bonusWalletLabel']]) }}@if($locale !== 'en') (≈ {{ $plan['bonusWalletIdrLabel'] }})@endif</li>
                            <li>{{ __('+:gb GB penyimpanan selama aktif', ['gb' => $plan['storage_gb']]) }}</li>
                            <li>{{ __('Akses chat, Studio & API') }}</li>
                        </ul>
                        <a href="{{ $plan['checkoutUrl'] }}" class="button {{ $plan['featured'] ? 'button-primary' : 'button-outline' }}">{{ __('Pilih :label', ['label' => strtolower($plan['label'])]) }} @include('public.partials.icon', ['name' => 'arrow'])</a>
                    </article>
                @endforeach
            </div></div>
        </div>
    </section>

    <section id="token-packages" class="pricing-catalog" aria-labelledby="token-packages-title">
        <div class="container">
            <div class="section-heading"><h2 id="token-packages-title">{{ __('Paket token media') }}</h2><p>{{ __('Untuk hasil Studio. Token terpisah dari Saldo AI dan tidak memerlukan langganan aktif.') }}</p></div>
            <div class="pricing-grid">
                @forelse($tokenPackages as $package)
                    <article class="pricing-card" data-token-package>
                        <div class="plan-heading"><h3>{{ $package['name'] }}</h3></div>
                        <p class="plan-price">{{ $package['priceLabel'] }}</p>
                        <p>{{ __(':count token media', ['count' => number_format($package['total_tokens'], 0, ',', $locale === 'en' ? ',' : '.')]) }}</p>
                        <a class="button button-outline" href="{{ $localeUrl('/deposit?tab=tokens') }}">{{ __('Isi token') }}</a>
                    </article>
                @empty
                    <p>{{ __('Paket token belum tersedia.') }}</p>
                @endforelse
            </div>
        </div>
    </section>

    <section id="wallet-pricing" class="pricing-principles" aria-labelledby="wallet-pricing-title">
        <div class="container">
            <div class="section-heading"><h2 id="wallet-pricing-title">{{ __('Saldo AI PAYG') }}</h2><p>{{ __('Chat & API ditagih sesuai token input, output, dan cache. Tarif jual dapat dilihat sebelum pemakaian.') }}</p></div>
            <p>@if($locale === 'en'){{ __('Isi saldo mulai :amount', ['amount' => '$'.number_format($walletMinimumUsd, 2, '.', ',')]) }}@else{{ __('Rp :rate = $1 Saldo AI', ['rate' => number_format($walletRate, 0, ',', '.')]) }}@endif</p>
            <p>{{ __('Saldo dan token tetap dapat dipakai setelah langganan berakhir, selama akun aktif dan memiliki izin fitur.') }}</p>
            <a class="button button-primary" href="{{ $localeUrl('/deposit?tab=wallet') }}">{{ __('Isi Saldo AI') }}</a>
        </div>
    </section>

    <section id="price-examples" class="pricing-catalog" aria-labelledby="price-examples-title">
        <div class="container">
            <div class="section-heading"><h2 id="price-examples-title">{{ __('Contoh harga') }}</h2><p>{{ __('Tarif jual model yang tersedia saat ini. Biaya akhir mengikuti model dan jumlah pemakaian.') }}</p></div>
            <div class="pricing-grid">
                @if($example = $priceExamples['image'])
                    <article class="pricing-card" data-image-price-example>
                        <div class="plan-heading"><h3>{{ $example['name'] }}</h3></div>
                        <p>{{ __(':count token / gambar', ['count' => $example['tokens']]) }}</p>
                        @if($locale !== 'en')<p>≈ Rp {{ number_format($example['idr'], 0, ',', '.') }}</p>@endif
                        <p class="plan-description">{{ __('Contoh gambar termurah per hasil. Nilai setara memakai paket token dengan harga per token terendah.') }}</p>
                    </article>
                @endif
                @foreach($priceExamples['chat'] as $example)
                    <article class="pricing-card" data-chat-price-example>
                        <div class="plan-heading"><h3>{{ $example['name'] }}</h3></div>
                        <p>{{ __('per 1 juta token') }}</p>
                        <ul>
                            <li>{{ __('Input') }}: ${{ rtrim(rtrim(number_format($example['input_usd'], 4, '.', ','), '0'), '.') }}@if($locale !== 'en') (≈ Rp {{ number_format($example['input_idr'], 0, ',', '.') }})@endif</li>
                            <li>{{ __('Output') }}: ${{ rtrim(rtrim(number_format($example['output_usd'], 4, '.', ','), '0'), '.') }}@if($locale !== 'en') (≈ Rp {{ number_format($example['output_idr'], 0, ',', '.') }})@endif</li>
                        </ul>
                    </article>
                @endforeach
                @if(!$priceExamples['image'] && !$priceExamples['chat'])
                    <p>{{ __('Contoh harga belum tersedia. Lihat katalog untuk tarif terbaru.') }}</p>
                @endif
            </div>
            <a class="text-link" href="{{ $localeUrl('/models') }}">{{ __('Lihat semua tarif model') }} @include('public.partials.icon', ['name' => 'arrow'])</a>
        </div>
    </section>


    <section class="pricing-payment" aria-labelledby="pricing-payment-title" data-motion-zone><div class="container pricing-payment-grid"><div data-reveal="line"><h2 id="pricing-payment-title">{{ __('Bayar dengan cara') }}<br><span>{{ __('yang kamu kenal.') }}</span></h2><p>{{ __('QRIS, transfer bank, dan e-wallet lokal. Aktivasi atau perpanjangan dilakukan setelah pembayaran diverifikasi.') }}</p><a class="text-link" href="{{ $localeUrl('/#payment') }}">{{ __('Lihat alur pembayaran') }} @include('public.partials.icon', ['name' => 'arrow'])</a></div><div class="pricing-payment-rail" data-reveal="wipe">@foreach($site['payments'] as $method)<span><img src="{{ $method['logo'] }}" width="95" height="32" alt="{{ $method['name'] }}" loading="lazy"><small>{{ $method['name'] }}</small></span>@endforeach</div></div></section>

    <section class="pricing-final" data-motion-zone><div class="closing-orbit orbit-close-one" aria-hidden="true"></div><div class="closing-orbit orbit-close-two" aria-hidden="true"></div><div class="container" data-reveal="line"><img src="/xsuper-icon-v2.png" width="54" height="54" alt="XSuper.ai" loading="lazy"><h2>{{ __('Mulai kecil.') }}<br><span>{{ __('Biarkan idemu tumbuh.') }}</span></h2><p>{{ __('Belum yakin paket mana? Ceritakan cara kerjamu—tim XSuper.ai membantu memilih tanpa memaksakan durasi.') }}</p><div><a class="button button-light" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Bicara dengan tim') }} @include('public.partials.icon', ['name' => 'external'])</a><a class="text-link" href="{{ $localeUrl('/refund-policy') }}">{{ __('Baca Refund Policy') }} @include('public.partials.icon', ['name' => 'arrow'])</a></div></div></section>
</main>
@endsection
