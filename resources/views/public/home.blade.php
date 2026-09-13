@extends('layouts.public')

@section('content')
<main id="main-content">
    <section class="hero-section" aria-labelledby="hero-title" data-motion-zone>
        <div class="container hero-grid">
            <div class="hero-copy" data-reveal="stagger">
                <h1 id="hero-title" data-reveal-item>{{ __('Satu ruang.') }}<br>{{ __('Semua') }} <span>{{ __('kemungkinan.') }}</span></h1>
                <p class="hero-description" data-reveal-item>{{ __('Platform AI untuk berkarya, belajar, dan membangun. Dari percakapan pertama sampai produk berikutnya—semuanya berawal di UltrAI.') }}</p>
                <div class="hero-actions" data-reveal-item><a href="{{ $localeUrl('/pricing') }}" class="button button-primary">{{ __('Temukan paketmu') }} @include('public.partials.icon', ['name' => 'arrow'])</a><a href="/chat" class="text-link">{{ __('Buka workspace') }} @include('public.partials.icon', ['name' => 'external'])</a></div>
                <div class="hero-footnote" data-reveal-item><span class="hero-rule" aria-hidden="true"></span><p>{{ __('AI premium. Harga tetap membumi.') }}<br>{{ __('Mulai') }} <strong>{{ $plans[0]['priceLabel'] }}</strong> {{ __('untuk satu hari.') }}</p></div>
            </div>
            <div class="orbit-stage" data-orbit-stage aria-label="{{ __('Pilihan model AI dalam platform UltrAI') }}">
                <div class="orbit-coordinate coordinate-top" aria-hidden="true"><span>{{ __('Pikiran berbeda.') }}</span><span>{{ __('Satu workspace.') }}</span></div>
                <div class="orbit-system">
                    <div class="orbit-ring ring-outer" aria-hidden="true"></div><div class="orbit-ring ring-inner" aria-hidden="true"></div><div class="orbit-ring ring-cross" aria-hidden="true"></div>
                    <div class="orbit-axis axis-horizontal" aria-hidden="true"></div><div class="orbit-axis axis-vertical" aria-hidden="true"></div>
                    <div class="orbit-core"><div class="core-ring" aria-hidden="true"></div><img src="/brands/ultrai/mark-256.webp" srcset="/brands/ultrai/mark-256.webp 256w, /brands/ultrai/mark-512.webp 512w" sizes="140px" width="140" height="140" alt="UltrAI" fetchpriority="high"><span>{{ __('Pusat kreativitasmu.') }}</span></div>
                    @foreach($site['models'] as $model)
                        <div class="orbit-arm arm-{{ $loop->index }}" style="--orbit-index: {{ $loop->index }}">
                            <button type="button" class="orbit-card {{ $loop->first ? 'is-selected' : '' }}" data-orbit-model="{{ $model['id'] }}" data-model-name="{{ $model['name'] }}" data-model-description="{{ $model['description'] }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}">
                                <span class="orbit-logo"><img src="{{ $model['logo'] }}" width="36" height="36" alt="" decoding="async"></span><span>{{ $model['name'] }}</span>
                            </button>
                        </div>
                    @endforeach
                </div>
                <div class="orbit-caption"><span class="orbit-selector-mark" aria-hidden="true"></span><div><strong id="orbit-model-name">{{ $site['models'][0]['name'] }}</strong><p id="orbit-description">{{ $site['models'][0]['description'] }}</p></div><span class="orbit-hint">{{ __('Pilih untuk') }}<br>{{ __('mengenal model') }}</span></div>
            </div>
        </div>
        <div class="container hero-bottom"><span>{{ __('Untuk ide yang belum punya batas.') }}</span><a href="{{ $localeUrl('/#models') }}">{{ __('Jelajahi kemungkinan') }} @include('public.partials.icon', ['name' => 'arrow'])</a><span>{{ __('Dirancang untuk cara kerjamu.') }}</span></div>
    </section>

    <section id="models" class="model-universe" aria-labelledby="models-title" data-motion-zone>
        <div class="container section-heading" data-reveal="rise"><h2 id="models-title">{{ __('Banyak cara berpikir.') }}<br><span>{{ __('Satu tempat bertemu.') }}</span></h2><p>{{ __('Pilih model yang cocok dengan pekerjaanmu. Identitas dan pembuatnya tetap jelas; pengalaman kerjanya tetap UltrAI.') }}</p></div>
        <div class="brand-rail" aria-label="{{ __('Model dan pembuat AI') }}">
            <div class="brand-track">
                @foreach($site['models'] as $model)
                    <div class="brand-cell"><img src="{{ $model['logo'] }}" width="46" height="46" alt="Logo {{ $model['name'] }}" loading="lazy"><div><strong>{{ $model['name'] }}</strong><span>{{ $model['maker'] }}</span></div></div>
                @endforeach
                @foreach($site['models'] as $model)
                    <div class="brand-cell" aria-hidden="true"><img src="{{ $model['logo'] }}" width="46" height="46" alt="" loading="lazy"><div><strong>{{ $model['name'] }}</strong><span>{{ $model['maker'] }}</span></div></div>
                @endforeach
            </div>
        </div>
        <p class="container model-disclaimer">{{ __('Pilihan model mengikuti ketersediaan layanan dan izin akun. Nama serta logo milik pemilik masing-masing, bukan klaim kemitraan atau model buatan UltrAI.') }}</p>
    </section>

    <section class="possibility-section" id="why-ultrai" aria-labelledby="possibility-title" data-motion-zone>
        <div class="container possibility-grid">
            <div class="possibility-copy" data-reveal="line"><h2 id="possibility-title">{{ __('Ide kecil.') }}<br><span class="large-word">{{ __('Efek besar.') }}</span></h2><p>{{ __('Kamu bawa rasa ingin tahu.') }}<br>{{ __('UltrAI menyediakan ruang untuk menjadikannya sesuatu yang nyata.') }}</p><a class="text-link" href="{{ $localeUrl('/#workspace') }}">{{ __('Lihat cara kerjanya') }} @include('public.partials.icon', ['name' => 'arrow'])</a></div>
            <div class="possibility-lines" data-reveal="stagger">
                <article data-reveal-item><span class="possibility-icon">@include('public.partials.icon', ['name' => 'chat'])</span><div><h3>{{ __('Pikirkan lebih jauh.') }}</h3><p>{{ __('Urai pertanyaan, temukan sudut pandang, lalu susun langkah yang bisa dikerjakan.') }}</p></div><span class="line-end" aria-hidden="true">@include('public.partials.icon', ['name' => 'plus'])</span></article>
                <article data-reveal-item><span class="possibility-icon">@include('public.partials.icon', ['name' => 'pen'])</span><div><h3>{{ __('Buat lebih bermakna.') }}</h3><p>{{ __('Dari draft pertama sampai ide visual. Satu workspace mengikuti arah kreativitasmu.') }}</p></div><span class="line-end" aria-hidden="true">@include('public.partials.icon', ['name' => 'plus'])</span></article>
                <article data-reveal-item><span class="possibility-icon">@include('public.partials.icon', ['name' => 'code'])</span><div><h3>{{ __('Bangun versi berikutnya.') }}</h3><p>{{ __('Diskusikan kode atau gunakan API untuk membawa AI ke aplikasi dan alat kerjamu.') }}</p></div><span class="line-end" aria-hidden="true">@include('public.partials.icon', ['name' => 'plus'])</span></article>
            </div>
        </div>
    </section>

    <section id="workspace" class="workspace-section" aria-labelledby="workspace-title" data-motion-zone>
        <div class="container">
            <div class="section-heading" data-reveal="rise"><h2 id="workspace-title">{{ __('Bukan halaman kosong.') }}<br><span>{{ __('Awal sesuatu yang besar.') }}</span></h2><p>{{ __('Pilih model. Bawa pertanyaan, potongan kode, atau dokumen. Kerjakan idemu dalam percakapan yang terus punya konteks.') }}</p></div>
            <div class="workspace-showcase" data-reveal="wipe">
                <aside class="showcase-sidebar" aria-label="{{ __('Fitur workspace') }}"><a href="/chat" class="showcase-brand"><img src="/brands/ultrai/mark-96.webp" width="32" height="32" alt="UltrAI" loading="lazy"><strong>UltrAI<span>.</span></strong></a><a href="/chat" class="showcase-new">@include('public.partials.icon', ['name' => 'plus']) {{ __('Mulai percakapan') }}</a><p>{{ __('Ruang untuk ide') }}</p><span class="showcase-item selected">@include('public.partials.icon', ['name' => 'chat']) {{ __('Percakapan & riset') }}</span><span class="showcase-item">@include('public.partials.icon', ['name' => 'code']) {{ __('Teman berpikir kode') }}</span><span class="showcase-item">@include('public.partials.icon', ['name' => 'file']) {{ __('Bawa bahan referensi') }}</span><div class="showcase-bottom">{{ __('Model pilihanmu.') }}<br>{{ __('Cara kerja UltrAI.') }}</div></aside>
                <div class="showcase-main">
                    <div class="showcase-top"><span>{{ __('Di balik setiap karya, ada percakapan.') }}</span><span class="example-label">{{ __('Contoh penggunaan') }}</span></div>
                    <div class="demo-tabs" id="demo-tabs" aria-label="{{ __('Contoh pekerjaan') }}"><button type="button" id="demo-writing-tab" data-demo-tab="writing" aria-controls="demo-writing">@include('public.partials.icon', ['name' => 'pen']) {{ __('Menulis') }}</button><button type="button" id="demo-coding-tab" data-demo-tab="coding" aria-controls="demo-coding">@include('public.partials.icon', ['name' => 'code']) Coding</button><button type="button" id="demo-planning-tab" data-demo-tab="planning" aria-controls="demo-planning">@include('public.partials.icon', ['name' => 'book']) {{ __('Merencanakan') }}</button></div>
                    <div id="demo-writing" class="demo-panel" data-demo-panel="writing" aria-labelledby="demo-writing-tab"><div class="demo-question"><span>K</span><p>{{ __('Bantu tulis pembuka untuk brand kopi lokal. Hangat, sederhana, tidak berlebihan.') }}</p></div><div class="demo-answer"><span class="answer-mark"><img src="/brands/ultrai/mark-96.webp" width="28" height="28" alt="" loading="lazy"></span><div><h3>{{ __('Selalu ada waktu') }}<br>{{ __('untuk mulai pelan.') }}</h3><p>{{ __('Secangkir kopi, percakapan kecil, dan jeda yang kamu butuhkan. Kami meracik kopi lokal untuk menemani hal sederhana yang layak dinikmati.') }}</p><span class="example-caption">{{ __('Contoh draft untuk brand fiktif, bukan respons AI langsung.') }}</span></div></div></div>
                    <div id="demo-coding" class="demo-panel" data-demo-panel="coding" aria-labelledby="demo-coding-tab"><div class="demo-question"><span>K</span><p>{{ __('Buat fungsi JavaScript untuk mengelompokkan catatan berdasarkan proyek.') }}</p></div><div class="demo-answer"><span class="answer-mark">@include('public.partials.icon', ['name' => 'code'])</span><div><h3>{{ __('Kode rapi.') }}<br>{{ __('Ide tetap bergerak.') }}</h3><pre><code>function groupByProject(notes) {
  return Object.groupBy(notes, note =&gt; note.project);
}</code></pre><span class="example-caption">{{ __('Cuplikan kode ilustratif; tinjau sebelum digunakan.') }}</span></div></div></div>
                    <div id="demo-planning" class="demo-panel" data-demo-panel="planning" aria-labelledby="demo-planning-tab"><div class="demo-question"><span>K</span><p>{{ __('Susun langkah awal untuk meluncurkan website portfolio pertama.') }}</p></div><div class="demo-answer"><span class="answer-mark">@include('public.partials.icon', ['name' => 'book'])</span><div><h3>{{ __('Mulai kecil.') }}<br>{{ __('Buat langkahnya jelas.') }}</h3><ol class="demo-plan"><li>{{ __('Pilih karya yang paling mewakili caramu berpikir.') }}</li><li>{{ __('Ceritakan masalah, proses, dan keputusan di baliknya.') }}</li><li>{{ __('Bangun halaman, lalu periksa di desktop dan ponsel.') }}</li></ol><span class="example-caption">{{ __('Contoh kerangka kerja, sesuaikan dengan kebutuhanmu.') }}</span></div></div></div>
                    <div class="showcase-composer"><span>@include('public.partials.icon', ['name' => 'plus']) {{ __('Pertanyaan berikutnya milikmu.') }}</span><a href="/chat" class="showcase-send" aria-label="{{ __('Buka Chat AI') }}">@include('public.partials.icon', ['name' => 'arrow'])</a></div>
                </div>
            </div>
            <div class="workspace-support"><span>@include('public.partials.icon', ['name' => 'chat']) {{ __('Riwayat percakapan') }}</span><span>@include('public.partials.icon', ['name' => 'file']) {{ __('Lampiran gambar & dokumen') }}</span><span><img class="feature-mark" src="/brands/ultrai/mark-96.webp" width="18" height="18" alt="" loading="lazy"> {{ __('Pilihan model dalam satu ruang') }}</span><a class="text-link" href="/chat">{{ __('Masuk ke workspace') }} @include('public.partials.icon', ['name' => 'external'])</a></div>
        </div>
    </section>

    <section id="developers" class="developer-section" aria-labelledby="developer-title" data-motion-zone>
        <div class="container developer-grid">
            <div class="developer-copy" data-reveal="line"><h2 id="developer-title">{{ __('Ide yang sama.') }}<br>{{ __('Skala yang') }} <span>{{ __('berbeda.') }}</span></h2><p>{{ __('Workspace untuk pekerjaanmu.') }}<br>{{ __('API untuk produk yang kamu bangun.') }}</p><p class="developer-detail">{{ __('Gunakan endpoint UltrAI dengan format API yang familiar. Integrasikan ke aplikasi, VSCode, Cursor, atau alat developer yang mendukung endpoint kompatibel.') }}</p><a href="/dashboard" class="button button-light">{{ __('Buka developer dashboard') }} @include('public.partials.icon', ['name' => 'arrow'])</a><div class="developer-endpoint"><span>Base URL</span><code>https://api.ultrai.id/v1</code></div></div>
            <div class="developer-terminal" data-reveal="wipe"><div class="terminal-title"><span>@include('public.partials.icon', ['name' => 'code']) {{ __('Ide → request → kemungkinan') }}</span><span>API UltrAI</span></div><div class="code-tabs" id="api-tabs" aria-label="{{ __('Bahasa contoh API') }}"><button type="button" id="api-curl-tab" data-code-tab="curl" aria-controls="api-curl">cURL</button><button type="button" id="api-python-tab" data-code-tab="python" aria-controls="api-python">Python</button></div><div id="api-curl" class="code-panel" data-code-panel="curl" aria-labelledby="api-curl-tab"><pre><code id="curl-code">curl https://api.ultrai.id/v1/chat/completions \
  -H "Authorization: Bearer $ULTRAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "model": "MODEL_ID",
    "messages": [{
      "role": "user",
      "content": "Halo, UltrAI!"
    }]
  }'</code></pre><button type="button" class="copy-code" data-copy-target="curl-code" hidden>@include('public.partials.icon', ['name' => 'copy']) {{ __('Salin contoh') }}</button></div><div id="api-python" class="code-panel" data-code-panel="python" aria-labelledby="api-python-tab"><pre><code id="python-code">import json, os, urllib.request

payload = {
    "model": "MODEL_ID",
    "messages": [{"role": "user", "content": "Halo!"}]
}
request = urllib.request.Request(
    "https://api.ultrai.id/v1/chat/completions",
    data=json.dumps(payload).encode(),
    headers={
        "Authorization": "Bearer " + os.environ["ULTRAI_API_KEY"],
        "Content-Type": "application/json"
    }, method="POST"
)
with urllib.request.urlopen(request) as response:
    print(json.load(response))</code></pre><button type="button" class="copy-code" data-copy-target="python-code" hidden>@include('public.partials.icon', ['name' => 'copy']) {{ __('Salin contoh') }}</button></div><p class="terminal-footnote">{{ __('Ganti MODEL_ID dengan model yang tersedia untuk akunmu. Simpan API key di server, bukan dalam kode browser.') }}</p><div class="request-flow" aria-hidden="true"><span>YOUR APP</span><i class="flow-track"><i class="flow-packet"></i></i><img src="/brands/ultrai/mark-96.webp" width="26" height="26" alt="" loading="lazy"><i class="flow-track"><i class="flow-packet packet-delayed"></i></i><span>YOUR NEXT IDEA</span></div></div>
        </div>
    </section>

    <section id="for-you" class="audience-section" aria-labelledby="audience-title" data-motion-zone>
        <div class="container audience-grid"><div class="audience-intro" data-reveal="rise"><h2 id="audience-title">{{ __('Bukan cuma') }}<br>{{ __('untuk') }} <span>{{ __('orang tech.') }}</span></h2><p>{{ __('Untuk siapa pun yang punya pertanyaan, pekerjaan, atau sesuatu yang ingin diwujudkan.') }}</p><div class="audience-art" aria-hidden="true"><span class="audience-arc arc-one"></span><span class="audience-arc arc-two"></span><span class="audience-arc arc-three"></span><span class="audience-dot"></span><strong>Your next<br>chapter.</strong></div></div><div class="audience-list" data-reveal="stagger">@foreach($site['audiences'] as $audience)<article class="audience-row" data-reveal-item><span class="audience-icon">@include('public.partials.icon', ['name' => ['dev' => 'code', 'student' => 'book', 'creator' => 'pen', 'biz' => 'briefcase', 'researcher' => 'research'][$audience['id']] ?? 'research'])</span><div><h3>{{ $audience['title'] }}</h3><p>{{ $audience['description'] }}</p><span class="audience-prompt">“{{ $audience['prompt'] }}”</span></div><a href="/chat" aria-label="{{ __('Buka workspace untuk :audience', ['audience' => $audience['title']]) }}">@include('public.partials.icon', ['name' => 'external'])</a></article>@endforeach</div></div>
    </section>


    <section id="payment" class="payment-section" aria-labelledby="payment-title" data-motion-zone>
        <div class="container payment-grid"><div class="payment-copy" data-reveal="line"><h2 id="payment-title">{{ __('Jarak ke ide berikutnya?') }}<br><span>{{ __('Satu pembayaran lokal.') }}</span></h2><p>{{ __('Tanpa harus mencari kartu kredit internasional. Pilih paket, konfirmasi metode pembayaran, lalu tim kami membantu aktivasi.') }}</p><ol class="payment-steps"><li><span>1</span>{{ __('Pilih paket sesuai kebutuhan') }}</li><li><span>2</span>{{ __('Konfirmasi & lakukan pembayaran') }}</li><li><span>3</span>{{ __('Akun diaktifkan setelah verifikasi') }}</li></ol><a href="https://wa.me/{{ $site['support']['phone'] }}" class="text-link">{{ __('Tanya cara pembayaran') }} @include('public.partials.icon', ['name' => 'external'])</a></div><div class="payment-board" data-reveal="wipe"><div class="payment-board-header"><span>{{ __('Cara bayar, pilihanmu.') }}</span><span>LOCAL CHECKOUT</span></div><div class="payment-path" aria-hidden="true"><span class="payment-signal"></span></div><div class="payment-methods">@foreach($site['payments'] as $method)<div class="payment-method" data-payment="{{ $method['id'] }}"><img src="{{ $method['logo'] }}" width="128" height="46" alt="{{ $method['name'] }}" loading="lazy"><span>{{ $method['name'] }}</span></div>@endforeach</div><div class="payment-confirmation"><span>@include('public.partials.icon', ['name' => 'check'])</span><div><strong>{{ __('Bayar dengan cara yang familiar.') }}</strong><p>{{ __('Aktivasi setelah pembayaran diverifikasi.') }}</p></div></div></div></div>
    </section>

    <section id="faq" class="faq-section" aria-labelledby="faq-title" data-motion-zone>
        <div class="container faq-grid"><div class="faq-intro" data-reveal="rise"><h2 id="faq-title">{{ __('Penasaran itu') }}<br><span>{{ __('awal yang baik.') }}</span></h2><p>{{ __('Beberapa jawaban sebelum kamu mulai.') }}</p><a href="https://wa.me/{{ $site['support']['phone'] }}" class="text-link">{{ __('Tanya langsung ke tim') }} @include('public.partials.icon', ['name' => 'external'])</a><div class="faq-symbol" aria-hidden="true">?</div></div><div class="faq-list" data-reveal="stagger">@foreach($site['faqs'] as $faq)<details data-reveal-item {{ $loop->first ? 'open' : '' }}><summary>{{ $faq['question'] }}<span>@include('public.partials.icon', ['name' => 'plus'])</span></summary><div class="faq-answer"><p>{{ $faq['answer'] }}</p></div></details>@endforeach</div></div>
    </section>

    <section class="closing-section" aria-labelledby="closing-title" data-motion-zone>
        <div class="closing-orbit orbit-close-one" aria-hidden="true"></div><div class="closing-orbit orbit-close-two" aria-hidden="true"></div><div class="container closing-content" data-reveal="line"><img src="/brands/ultrai/mark-96.webp" width="58" height="58" alt="UltrAI" loading="lazy"><h2 id="closing-title">{{ __('Idemu terlalu bagus') }}<br>{{ __('untuk') }} <span>{{ __('berhenti di kepala.') }}</span></h2><p>{{ __('Buka ruang untuk kemungkinan berikutnya.') }}</p><div class="closing-actions"><a href="{{ $localeUrl('/pricing') }}" class="button button-light">{{ __('Lihat paket UltrAI') }} @include('public.partials.icon', ['name' => 'arrow'])</a><a href="/chat" class="text-link">{{ __('Sudah punya akun? Buka workspace') }} @include('public.partials.icon', ['name' => 'external'])</a></div></div>
    </section>
</main>
@endsection
