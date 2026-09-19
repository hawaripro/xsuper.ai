@extends('layouts.public')

@section('content')
<main id="main-content" class="policy-page">
    <div class="container">
        <header class="policy-hero"><a class="text-link" href="{{ $localeUrl('/') }}">@include('public.partials.icon', ['name' => 'arrow', 'class' => 'back-arrow']) {{ __('Kembali ke XSuper.ai') }}</a><h1>{{ $policy['title'] }}</h1><p>{{ $policy['description'] }}</p></header>
        <div class="policy-layout">
            <aside class="policy-toc">
                <h2>{{ __('Di halaman ini') }}</h2>
                <nav aria-label="{{ __('Daftar isi kebijakan') }}">
                    @foreach($policy['sections'] as $section)
                        <a href="#section-{{ $loop->iteration }}">{{ $section['heading'] }}</a>
                    @endforeach
                </nav>
                <div class="policy-other-links">
                    <h2>{{ __('Kebijakan terkait') }}</h2>
                    @foreach($site['policies'] as $slug => $document)
                        @if($slug !== $policySlug)
                            <a href="{{ $localeUrl('/'.$slug) }}">{{ $document['title'] }}</a>
                        @endif
                    @endforeach
                </div>
            </aside>
            <div class="policy-content">
                @foreach($policy['sections'] as $section)
                    <section class="policy-section" id="section-{{ $loop->iteration }}"><h2>{{ $section['heading'] }}</h2>@foreach($section['paragraphs'] as $paragraph)<p>{{ $paragraph }}</p>@endforeach @if(!empty($section['items']))<ul>@foreach($section['items'] as $item)<li>{{ $item }}</li>@endforeach</ul>@endif</section>
                @endforeach
                <section class="policy-contact"><h2>{{ __('Masih ada pertanyaan?') }}</h2><p>{{ __('Tim XSuper.ai dapat membantu menjelaskan kebijakan dan menangani permintaanmu.') }}</p><a class="button button-primary" href="https://wa.me/{{ $site['support']['phone'] }}">{{ __('Hubungi melalui WhatsApp') }} @include('public.partials.icon', ['name' => 'external'])</a></section>
            </div>
        </div>
    </div>
</main>
@endsection
