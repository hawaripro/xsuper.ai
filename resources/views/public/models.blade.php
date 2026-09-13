@extends('layouts.public')

@section('content')
<main id="main-content" class="model-catalog-page">
    <section class="model-marketplace" aria-labelledby="model-catalog-title">
        <div class="model-marketplace-shell">
            <header class="model-marketplace-header">
                <div><h1 id="model-catalog-title">{{ __('Katalog Model AI') }}</h1><p><output data-model-count>{{ count($models) }}</output> {{ __('model tersedia') }}</p></div>
                <label class="model-marketplace-search">@include('public.partials.icon', ['name' => 'search'])<span class="sr-only">{{ __('Cari model') }}</span><input type="search" data-model-search placeholder="{{ __('Cari model…') }}" autocomplete="off"></label>
            </header>

            <div class="model-marketplace-layout">
                <aside class="model-filter-sidebar" data-model-filter-sidebar aria-label="{{ __('Filter model') }}">
                    <div class="model-filter-title"><strong>{{ __('Filter') }}</strong><button type="button" data-model-clear>{{ __('Reset') }}</button></div>
                    <fieldset><legend>{{ __('Modalitas input') }}</legend>@foreach($modalityCounts as $modality => $count)<label><input type="checkbox" value="{{ strtolower($modality) }}" data-model-modality-check><span>{{ ucfirst($modality) }}</span><small>{{ $count }}</small></label>@endforeach</fieldset>
                    <fieldset><legend>{{ __('Kemampuan') }}</legend>@foreach($capabilityCounts as $capability => $count)<label><input type="checkbox" value="{{ strtolower($capability) }}" data-model-capability-check><span>{{ ucfirst($capability) }}</span><small>{{ $count }}</small></label>@endforeach</fieldset>
                    <fieldset><legend>{{ __('Penagihan') }}</legend><label><input type="checkbox" value="subscription" data-model-billing-check><span>{{ __('Langganan') }}</span><small>{{ collect($models)->where('rates', [])->count() }}</small></label><label><input type="checkbox" value="payg" data-model-billing-check><span>PAYG</span><small>{{ collect($models)->filter(fn($model) => !empty($model['rates']))->count() }}</small></label></fieldset>
                    <fieldset><legend>{{ __('Context window') }}</legend><label><input type="radio" name="model-context" value="" data-model-context-check checked><span>{{ __('Semua') }}</span></label><label><input type="radio" name="model-context" value="128000" data-model-context-check><span>128K+</span></label><label><input type="radio" name="model-context" value="1000000" data-model-context-check><span>1M+</span></label></fieldset>
                    <fieldset><legend>{{ __('Penyedia') }}</legend>@foreach($providerCounts as $provider => $count)<label><input type="checkbox" value="{{ strtolower($provider) }}" data-model-provider-check><span>{{ $provider }}</span><small>{{ $count }}</small></label>@endforeach</fieldset>
                </aside>

                <section class="model-results" aria-label="{{ __('Hasil model') }}">
                    <div class="model-category-tabs" role="tablist" aria-label="{{ __('Kategori model') }}">
                        <button type="button" class="is-active" data-model-category="">{{ __('Semua') }} <span>{{ count($models) }}</span></button>
                        @foreach($modalityCounts as $modality => $count)<button type="button" data-model-category="{{ strtolower($modality) }}">{{ ucfirst($modality) }} <span>{{ $count }}</span></button>@endforeach
                    </div>
                    <div class="model-table" data-model-table>
                        <div class="model-table-head"><span>{{ __('Model') }}</span><span>{{ __('Input / 1M') }}</span><span>{{ __('Output / 1M') }}</span><span>{{ __('Cache read') }}</span><span>{{ __('Cache write') }}</span><span>{{ __('Context') }}</span></div>
                        <div class="model-table-body" data-model-grid>
                            @foreach($models as $model)
                                @php($search = strtolower($model['name'].' '.$model['id'].' '.$model['provider'].' '.implode(' ', $model['capabilities']).' '.implode(' ', $model['modalities'])))
                                @php($billing = empty($model['rates']) ? 'subscription' : 'payg')
                                <article id="{{ $model['id'] }}" class="model-row" data-model-card data-model-search-text="{{ $search }}" data-model-provider="{{ strtolower($model['provider']) }}" data-model-capabilities="{{ strtolower(implode(' ', $model['capabilities'])) }}" data-model-modalities="{{ strtolower(implode(' ', $model['modalities'])) }}" data-model-billing="{{ $billing }}" data-model-context="{{ $model['context'] ?? 0 }}">
                                    <div class="model-name-cell"><img class="model-row-logo" data-logo-id="{{ $model['id'] }}" src="{{ $model['logo'] }}" width="32" height="32" alt="" loading="lazy"><div><strong>{{ $model['provider'] }}: {{ $model['name'] }}</strong><code>{{ $model['id'] }}</code></div><button type="button" class="model-copy" data-copy-model="{{ $model['id'] }}" aria-label="{{ __('Salin ID model') }}">@include('public.partials.icon', ['name' => 'copy'])</button></div>
                                    <span class="model-price-cell">{{ $model['inputRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['inputRate']['price_usd'], 6, '.', ',') : 'Rp '.number_format((float)$model['inputRate']['price_idr'], 2, ',', '.')) : __('Termasuk') }}</span>
                                    <span class="model-price-cell">{{ $model['outputRate'] ? ($locale === 'en' ? '$'.number_format((float)$model['outputRate']['price_usd'], 6, '.', ',') : 'Rp '.number_format((float)$model['outputRate']['price_idr'], 2, ',', '.')) : __('Termasuk') }}</span>
                                    <span class="model-price-cell">N/A</span><span class="model-price-cell">N/A</span>
                                    <span class="model-context-cell">{{ $model['context'] ? number_format($model['context'] / 1000, 0).'K' : 'N/A' }}</span>
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
