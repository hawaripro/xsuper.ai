<?php

namespace App\Http\Controllers;

use App\Models\DurationPackagePrice;
use App\Models\UsageRate;
use App\Services\AiProxyService;
use App\Services\ReferralService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;

class PublicSiteController extends Controller
{
    public function home(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->legacyLanguageRedirect($request)) {
            return $redirect;
        }

        if ($request->filled('ref')) {
            app(ReferralService::class)->capture($request, (string) $request->query('ref'));
        }

        $data = $this->sharedData($request);
        $site = $data['site'];
        $data['page'] = $this->pageMetadata([
            'title' => __('UltrAI — AI-Powered Platform for UMKM Indonesia'),
            'description' => $site['description'],
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1',
            'indexable' => true,
            'type' => 'home',
        ], '/', $data['locale'], $site['url']);
        $data['structuredData'] = [
            '@context' => 'https://schema.org',
            '@graph' => [
                [
                    '@type' => 'SoftwareApplication',
                    '@id' => $site['url'].'/#application',
                    'name' => $site['name'],
                    'url' => $site['url'],
                    'applicationCategory' => 'DeveloperApplication',
                    'operatingSystem' => 'Web',
                    'description' => $site['description'],
                    'provider' => [
                        '@type' => 'Organization',
                        'name' => $site['name'],
                        'url' => $site['url'],
                        'logo' => $site['url'].'/ultr-icons.png',
                        'contactPoint' => [
                            '@type' => 'ContactPoint',
                            'telephone' => '+'.$site['support']['phone'],
                            'contactType' => 'customer service',
                            'availableLanguage' => ['Indonesian', 'English'],
                        ],
                    ],
                ],
                [
                    '@type' => 'FAQPage',
                    '@id' => $data['page']['canonical'].'#faq',
                    'mainEntity' => array_map(fn (array $faq): array => [
                        '@type' => 'Question',
                        'name' => $faq['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $faq['answer']],
                    ], $site['faqs']),
                ],
            ],
        ];

        return $this->publicResponse('public.home', $data);
    }

    public function pricing(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->legacyLanguageRedirect($request)) {
            return $redirect;
        }

        $data = $this->sharedData($request);
        $site = $data['site'];
        $startingPrice = $data['plans'][0]['priceLabel'];
        $data['page'] = $this->pageMetadata([
            'title' => __('Harga UltrAI — Pilih Durasi Akses AI'),
            'description' => __('Pilih paket UltrAI mulai :price: satu hari, satu minggu, satu bulan, hingga satu tahun. Harga dan durasi tampil transparan.', ['price' => $startingPrice]),
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
            'indexable' => true,
            'type' => 'pricing',
        ], '/pricing', $data['locale'], $site['url']);
        $data['structuredData'] = [
            '@context' => 'https://schema.org',
            '@graph' => [[
                '@type' => 'SoftwareApplication',
                '@id' => $site['url'].'/#application',
                'name' => $site['name'],
                'url' => $site['url'],
                'applicationCategory' => 'DeveloperApplication',
                'operatingSystem' => 'Web',
                'description' => $site['description'],
                'offers' => array_map(fn (array $plan): array => [
                    '@type' => 'Offer',
                    'sku' => $plan['id'],
                    'name' => 'UltrAI '.$plan['label'],
                    'price' => $plan['price'],
                    'priceCurrency' => $plan['priceCurrency'],
                    'url' => $data['page']['canonical'],
                ], $data['plans']),
                'provider' => ['@type' => 'Organization', 'name' => $site['name'], 'url' => $site['url']],
            ]],
        ];

        return $this->publicResponse('public.pricing', $data);
    }

    public function models(Request $request, AiProxyService $proxy): Response|RedirectResponse
    {
        if ($redirect = $this->legacyLanguageRedirect($request)) {
            return $redirect;
        }

        $data = $this->sharedData($request);
        $data['models'] = $this->modelCatalog($proxy, $data['site']);
        $data['providers'] = array_values(array_unique(array_column($data['models'], 'provider')));
        $data['capabilityCounts'] = collect($data['models'])
            ->flatMap(fn (array $model): array => $model['capabilities'])
            ->countBy()
            ->sortDesc()
            ->all();
        $data['providerCounts'] = collect($data['models'])->countBy('provider')->sortDesc()->all();
        $data['modalityCounts'] = collect($data['models'])
            ->flatMap(fn (array $model): array => $model['modalities'])
            ->countBy()
            ->sortDesc()
            ->all();
        sort($data['providers']);
        $data['page'] = $this->pageMetadata([
            'title' => __('Katalog Model AI — UltrAI'),
            'description' => __('Jelajahi model AI yang tersedia di UltrAI berdasarkan penyedia, kemampuan, context window, dan tarif penggunaan.'),
            'robots' => 'index, follow, max-image-preview:large, max-snippet:-1',
            'indexable' => true,
            'type' => 'models',
        ], '/models', $data['locale'], $data['site']['url']);
        $data['structuredData'] = [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => __('Katalog Model AI'),
            'itemListElement' => array_map(fn (array $model, int $index): array => [
                '@type' => 'ListItem',
                'position' => $index + 1,
                'name' => $model['name'],
                'url' => $data['page']['canonical'].'#'.rawurlencode($model['id']),
            ], $data['models'], array_keys($data['models'])),
        ];

        return $this->publicResponse('public.models', $data);
    }

    public function policy(Request $request, ?string $policy = null): Response|RedirectResponse
    {
        $policy ??= $request->route('policy', '');
        if ($redirect = $this->legacyLanguageRedirect($request)) {
            return $redirect;
        }

        $data = $this->sharedData($request);
        abort_unless(isset($data['site']['policies'][$policy]), 404);
        $document = $data['site']['policies'][$policy];
        $data['page'] = $this->pageMetadata([
            'title' => $document['title'].' — UltrAI',
            'description' => $document['description'],
            'robots' => 'index, follow, max-image-preview:large',
            'indexable' => true,
            'type' => 'policy',
        ], '/'.$policy, $data['locale'], $data['site']['url']);
        $data['policy'] = $document;
        $data['policySlug'] = $policy;
        $data['structuredData'] = [];

        return $this->publicResponse('public.policy', $data);
    }

    public function sitemap(): Response
    {
        $site = config('marketing');
        $paths = ['/', '/pricing', '/models'];
        foreach (array_keys($site['policies']) as $slug) {
            $paths[] = '/'.$slug;
        }

        $urls = [];
        foreach ($paths as $path) {
            $urls[] = $path === '/' ? $site['url'] : $site['url'].$path;
            $urls[] = $path === '/' ? $site['url'].'/en' : $site['url'].'/en'.$path;
        }

        return response()->view('public.sitemap', ['urls' => $urls], 200)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function sharedData(Request $request): array
    {
        $locale = $request->route('locale', 'id');
        app()->setLocale($locale);
        $site = $this->translateValue(config('marketing'));
        $descriptions = [
            '1_day' => __('Satu hari untuk mencoba cara kerja baru.'),
            '1_week' => __('Teman untuk satu tugas atau proyek singkat.'),
            '1_month' => __('Ruang untuk ide dan pekerjaan sehari-hari.'),
            '3_months' => __('Jaga ritme belajar dan proyekmu.'),
            '6_months' => __('Untuk proses kreatif yang terus tumbuh.'),
            '12_months' => __('Temani rencana dan karya sepanjang tahun.'),
        ];
        $plans = [];
        foreach (DurationPackagePrice::catalog() as $id => $package) {
            if (! $package['is_active']) {
                continue;
            }
            $label = __($package['label']);
            $isUsd = $locale === 'en';
            $price = $isUsd ? (float) $package['price_usd'] : (int) $package['price_idr'];
            $priceLabel = $isUsd ? '$'.number_format($price, 2, '.', ',') : 'Rp '.number_format($price, 0, ',', '.');
            $perDay = $price / $package['days'];
            $perDayLabel = $isUsd ? '≈ $'.number_format($perDay, 2, '.', ',') : __('Sekitar Rp').' '.number_format((int) ceil($perDay), 0, ',', '.');
            $message = __('Halo UltrAI, saya ingin bertanya dan membeli paket :label (:price).', ['label' => $label, 'price' => $priceLabel]);
            $plans[] = [
                'id' => $id,
                ...$package,
                'label' => $label,
                'price' => $price,
                'priceIdr' => (int) $package['price_idr'],
                'priceUsd' => (float) $package['price_usd'],
                'priceCurrency' => $isUsd ? 'USD' : 'IDR',
                'priceLabel' => $priceLabel,
                'perDayLabel' => $perDayLabel,
                'description' => $descriptions[$id],
                'featured' => $id === '1_month',
                'active' => $package['is_active'],
                'checkoutUrl' => 'https://wa.me/'.$site['support']['phone'].'?text='.rawurlencode($message),
            ];
        }
        abort_if($plans === [], 503, 'No active duration packages are configured.');

        return [
            'site' => $site,
            'plans' => $plans,
            'locale' => $locale,
            'localeUrl' => fn (string $path): string => $this->localizedPath($path, $locale),
        ];
    }

    private function pageMetadata(array $metadata, string $path, string $locale, string $origin): array
    {
        $indonesian = $path === '/' ? $origin : $origin.$path;
        $english = $path === '/' ? $origin.'/en' : $origin.'/en'.$path;

        return [
            ...$metadata,
            'canonical' => $locale === 'en' ? $english : $indonesian,
            'locale' => $locale,
            'ogLocale' => $locale === 'en' ? 'en_US' : 'id_ID',
            'alternateLocale' => $locale === 'en' ? 'id_ID' : 'en_US',
            'alternates' => ['id' => $indonesian, 'en' => $english, 'x-default' => $indonesian],
            'languageUrls' => ['id' => $path, 'en' => $path === '/' ? '/en' : '/en'.$path],
        ];
    }

    private function publicResponse(string $view, array $data): Response
    {
        return response()->view($view, $data)
            ->header('Content-Language', $data['locale']);
    }

    private function localizedPath(string $path, string $locale): string
    {
        if ($locale !== 'en' || str_starts_with($path, '#') || preg_match('/^https?:\/\//', $path)) {
            return $path;
        }

        if ($path === '/') {
            return '/en';
        }
        if (str_starts_with($path, '/#')) {
            return '/en'.substr($path, 1);
        }

        return '/en'.$path;
    }

    private function legacyLanguageRedirect(Request $request): ?RedirectResponse
    {
        $language = $request->query('lang');
        if (! in_array($language, ['id', 'en'], true)) {
            return null;
        }
        $path = '/'.ltrim($request->path(), '/');
        if ($path === '/') {
            $path = '';
        }
        if ($path === '/en' || str_starts_with($path, '/en/')) {
            $path = substr($path, 3);
        }
        $target = $language === 'en' ? '/en'.$path : ($path ?: '/');

        return redirect($target, 301);
    }

    private function modelCatalog(AiProxyService $proxy, array $site): array
    {
        $known = collect($site['models'])->keyBy('id');
        $rawModels = collect(Cache::remember('public-model-catalog-v2', now()->addMinutes(10), fn (): array => $proxy->getAllModels()));
        if ($rawModels->isEmpty()) {
            $rawModels = $known->values();
        }
        $rates = collect(UsageRate::publicCatalog())->flatten(1)->groupBy('model');

        return $rawModels
            ->filter(fn (array $model): bool => ($model['id'] ?? '') !== '')
            ->map(function (array $model) use ($known, $rates): array {
                $id = $model['id'];
                $fallback = $known->get($id) ?? $known->first(fn (array $item): bool => str_contains(strtolower($id), strtolower($item['id'])));
                $provider = $model['owned_by'] ?? $model['provider'] ?? $fallback['maker'] ?? $this->providerFromModel($id);
                $capabilities = $model['capabilities'] ?? $model['modalities'] ?? [$model['category'] ?? 'chat'];
                if (is_string($capabilities)) {
                    $capabilities = [$capabilities];
                }
                $modelRates = $rates->get($id, collect());
                $category = strtolower((string) ($model['category'] ?? 'chat'));
                $capabilities = array_values(array_unique(array_filter([...$capabilities, $category])));
                $modalities = ['text'];
                foreach ($capabilities as $capability) {
                    if (in_array($capability, ['vision', 'image', 'file', 'audio', 'video', 'embeddings'], true)) {
                        $modalities[] = $capability === 'vision' ? 'image' : $capability;
                    }
                }
                $modalities = array_values(array_unique($modalities));
                $rateList = $modelRates->values()->all();
                $inputRate = $modelRates->firstWhere('meter', 'input_tokens');
                $outputRate = $modelRates->firstWhere('meter', 'output_tokens');

                return [
                    'id' => $id,
                    'name' => $model['name'] ?? $fallback['name'] ?? $id,
                    'provider' => $provider,
                    'description' => $fallback['description'] ?? __('Model AI untuk percakapan, analisis, dan pekerjaan kreatif.'),
                    'logo' => $fallback['logo'] ?? '/brands/ultrai/mark-96.webp',
                    'context' => $model['context_length'] ?? $model['context_window'] ?? null,
                    'capabilities' => $capabilities,
                    'modalities' => $modalities,
                    'rates' => $rateList,
                    'inputRate' => $inputRate,
                    'outputRate' => $outputRate,
                ];
            })
            ->unique('id')
            ->sortBy(fn (array $model): string => strtolower($model['provider'].' '.$model['name']))
            ->values()
            ->all();
    }

    private function providerFromModel(string $id): string
    {
        return match (true) {
            str_contains($id, 'claude') => 'Anthropic',
            str_contains($id, 'gpt'), str_contains($id, 'o1'), str_contains($id, 'o3') => '[OI]',
            str_contains($id, 'gemini') => 'Google',
            str_contains($id, 'deepseek') => 'DeepSeek',
            str_contains($id, 'glm') => 'Z.ai',
            str_contains($id, 'kimi') => 'Moonshot AI',
            default => 'UltrAI Catalog',
        };
    }

    private function translateValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return __($value);
        }
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->translateValue($item);
        }

        return $value;
    }
}
