@extends('layouts.public')

@section('content')
<main id="main-content" class="model-catalog-page">
    <section class="model-marketplace" aria-labelledby="model-catalog-title">
        <div class="model-marketplace-shell">
            <header class="model-marketplace-header">
                <div><h1 id="model-catalog-title">{{ __('Katalog Model AI') }}</h1><p><output data-model-count>{{ count($models) }}</output> {{ __('model tersedia') }}</p><p>{{ __('Tarif PAYG per 1 juta token. Token generator ditagih per gambar, video, atau audio.') }}</p></div>
                <div class="model-marketplace-controls">
                    <label class="model-marketplace-search">@include('public.partials.icon', ['name' => 'search'])<span class="sr-only">{{ __('Cari model') }}</span><input type="search" data-model-search placeholder="{{ __('Cari model…') }}" autocomplete="off"></label>
                    <button type="button" class="model-filter-toggle" data-model-filter-toggle aria-expanded="false" aria-controls="model-filter-sidebar">@include('public.partials.icon', ['name' => 'menu'])<span>{{ __('Filter') }}</span></button>
                </div>
            </header>

            <div class="model-marketplace-layout">
                <div class="model-filter-backdrop" data-model-filter-backdrop hidden></div>
                <aside id="model-filter-sidebar" class="model-filter-sidebar" data-model-filter-sidebar aria-label="{{ __('Filter model') }}">
                    <div class="model-filter-title"><strong>{{ __('Filter') }}</strong><button type="button" data-model-clear>{{ __('Reset') }}</button></div>
                    <fieldset><legend>{{ __('Modalitas input') }}</legend>@foreach($modalityCounts as $modality => $count)<label><input type="checkbox" value="{{ strtolower($modality) }}" data-model-modality-check><span>{{ ucfirst($modality) }}</span><small>{{ $count }}</small></label>@endforeach</fieldset>
                    <fieldset><legend>{{ __('Kemampuan') }}</legend>@foreach($capabilityCounts as $capability => $count)<label><input type="checkbox" value="{{ strtolower($capability) }}" data-model-capability-check><span>{{ ucfirst($capability) }}</span><small>{{ $count }}</small></label>@endforeach</fieldset>
                    <fieldset><legend>{{ __('Penagihan') }}</legend><label><input type="checkbox" value="subscription" data-model-billing-check><span>{{ __('Langganan') }}</span><small>{{ $billingCounts['subscription'] ?? 0 }}</small></label><label><input type="checkbox" value="payg" data-model-billing-check><span>PAYG</span><small>{{ $billingCounts['payg'] ?? 0 }}</small></label><label><input type="checkbox" value="tokens" data-model-billing-check><span>{{ __('Token generator') }}</span><small>{{ $billingCounts['tokens'] ?? 0 }}</small></label></fieldset>
                    <fieldset><legend>{{ __('Context window') }}</legend><label><input type="radio" name="model-context" value="" data-model-context-check checked><span>{{ __('Semua') }}</span></label><label><input type="radio" name="model-context" value="128000" data-model-context-check><span>128K+</span></label><label><input type="radio" name="model-context" value="1000000" data-model-context-check><span>1M+</span></label></fieldset>
                </aside>

                <section class="model-results" aria-label="{{ __('Hasil model') }}">
                    <div class="model-category-tabs" role="tablist" aria-label="{{ __('Kategori model') }}">
                        <button type="button" class="is-active" data-model-category="">{{ __('Semua') }} <span>{{ count($models) }}</span></button>
                        @foreach($modalityCounts as $modality => $count)<button type="button" data-model-category="{{ strtolower($modality) }}">{{ ucfirst($modality) }} <span>{{ $count }}</span></button>@endforeach
                    </div>
                    <div class="model-table" data-model-table>
                        <div class="model-table-head"><span>{{ __('Model') }}</span><span>{{ __('Input') }}</span><span>{{ __('Output') }}</span><span>{{ __('Cache read') }}</span><span>{{ __('Cache write') }}</span><span>{{ __('Context') }}</span></div>
                        <div class="model-table-body" data-model-grid>
                            @foreach($models as $model)
                                @php($search = strtolower($model['name'].' '.$model['id'].' '.$model['provider'].' '.implode(' ', $model['capabilities']).' '.implode(' ', $model['modalities'])))
                                @php($billing = $model['billing'])
                                <article id="{{ $model['id'] }}" class="model-row" data-model-card data-model-search-text="{{ $search }}" data-model-provider="{{ strtolower($model['provider']) }}" data-model-capabilities="{{ strtolower(implode(' ', $model['capabilities'])) }}" data-model-modalities="{{ strtolower(implode(' ', $model['modalities'])) }}" data-model-billing="{{ $billing }}" data-model-context="{{ $model['context'] ?? 0 }}">
                                    <div class="model-name-cell"><img class="model-row-logo" data-logo-id="{{ $model['id'] }}" src="{{ $model['logo'] }}" width="32" height="32" alt="" loading="lazy" onerror="this.onerror=null;this.src='/xsuper-symbol.png'"><div><strong>{{ $model['name'] }}</strong><code>{{ $model['id'] }}</code><p>{{ $model['description'] }}</p><div class="model-row-badges">@foreach($model['badges'] as $badge)<span>{{ $badge }}</span>@endforeach @foreach($model['modalities'] as $modality)<span>{{ ucfirst($modality) }}</span>@endforeach</div></div><button type="button" class="model-copy" data-copy-model="{{ $model['id'] }}" aria-label="{{ __('Salin ID model') }}">@include('public.partials.icon', ['name' => 'copy'])</button></div>
                                    <span class="model-price-cell">{{ $billing === 'tokens' ? '—' : ($model['inputRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['inputRate']['price_usd'], 3, '.', ',') : 'Rp '.number_format((float)$model['inputRate']['price_idr'], 0, ',', '.')) : __('Termasuk')) }}</span>
                                    <span class="model-price-cell">
                                        @if($billing === 'tokens')
                                            @if($model['tokenCost'] > 0)
                                                {{ trans_choice(match ($model['generationUnit']) { 'image' => ':count token / gambar', 'video' => ':count token / video', 'audio' => ':count token / audio' }, $model['tokenCost'], ['count' => number_format($model['tokenCost'], 0, '.', $locale === 'en' ? ',' : '.')], $locale) }}
                                            @else
                                                {{ __('Tarif belum tersedia') }}
                                            @endif
                                        @else
                                            {{ $model['outputRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['outputRate']['price_usd'], 3, '.', ',') : 'Rp '.number_format((float)$model['outputRate']['price_idr'], 0, ',', '.')) : __('Termasuk') }}
                                        @endif
                                    </span>
                                    <span class="model-price-cell">{{ $model['cacheReadRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['cacheReadRate']['price_usd'], 3, '.', ',') : 'Rp '.number_format((float)$model['cacheReadRate']['price_idr'], 0, ',', '.')) : '—' }}</span><span class="model-price-cell">{{ $model['cacheWriteRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['cacheWriteRate']['price_usd'], 3, '.', ',') : 'Rp '.number_format((float)$model['cacheWriteRate']['price_idr'], 0, ',', '.')) : '—' }}</span>
                                    <span class="model-context-cell">{{ $model['context'] ? ($model['context'] >= 1000000 ? number_format($model['context'] / 1000000, 0).'M' : number_format($model['context'] / 1000, 0).'K') : 'N/A' }}@if($model['maxOutput'])<small>{{ number_format($model['maxOutput'] / 1000, 0) }}K {{ __('output') }}</small>@endif</span>
                                </article>
                            @endforeach
                        </div>
                    </div>
                    <div class="catalog-empty" data-model-empty hidden><strong>{{ __('Tidak ada model yang cocok.') }}</strong><p>{{ __('Ubah pencarian atau filter untuk melihat model lain.') }}</p></div>
                </section>
            </div>
        </div>
    </section>
</main>
@endsection
