<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSiteController;
use App\Models\AiModelProfile;
use App\Models\AiProviderProfile;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.umami.id' => null]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_home_exposes_only_recent_approved_paid_subscription_events_without_private_order_data(): void
    {
        Carbon::setTestNow('2026-05-16 12:00:00');
        $buyer = User::factory()->create([
            'id' => 864209731,
            'name' => 'Rahasia Pembeli',
            'email' => 'rahasia@example.test',
        ]);
        DurationOrder::create([
            'user_id' => $buyer->id,
            'package' => '1_month',
            'days' => 30,
            'price' => 53_217,
            'payment_method' => 'qris',
            'payment_reference' => '44444444-4444-4444-8444-444444444444',
            'note' => 'Private verification details',
            'status' => 'approved',
            'approved_at' => now()->subMinutes(38),
        ]);

        foreach ([
            ['package' => '1_week', 'price' => 20_000, 'status' => 'pending', 'approved_at' => now()],
            ['package' => '3_months', 'price' => 135_000, 'status' => 'rejected', 'approved_at' => now()],
            ['package' => 'manual', 'price' => 1, 'status' => 'approved', 'approved_at' => now()],
            ['package' => '6_months', 'price' => 0, 'status' => 'approved', 'approved_at' => now()],
            ['package' => '12_months', 'price' => 499_000, 'status' => 'approved', 'approved_at' => null],
            ['package' => 'custom', 'price' => 25_000, 'status' => 'approved', 'approved_at' => now()],
            ['package' => '1_day', 'price' => 5_000, 'status' => 'approved', 'approved_at' => now()->subDays(8)],
            ['package' => '1_day', 'price' => 5_000, 'status' => 'approved', 'approved_at' => now()->addMinute()],
        ] as $order) {
            DurationOrder::create([
                'user_id' => $buyer->id,
                'days' => 7,
                'payment_method' => 'qris',
                ...$order,
            ]);
        }

        $content = $this->get('/')->assertOk()->getContent();
        $html = $this->html($content);
        $region = $html->query('//*[@data-purchase-region]');
        $items = $html->query('//*[@data-purchase-item]');

        $this->assertCount(1, $region);
        $this->assertSame('status', $region->item(0)->getAttribute('role'));
        $this->assertCount(1, $items);
        $this->assertStringContainsString('1 Bulan', $this->text($items->item(0)->textContent));
        $this->assertStringContainsString('16 Mei 2026', $this->text($items->item(0)->textContent));
        $this->assertCount(1, $html->query('//*[@data-purchase-region]//button[@data-purchase-dismiss][@type="button"][@aria-label!=""]'));
        $this->assertSame('', $html->evaluate('string(//*[@id="site-status"])'));
        foreach ([
            '864209731', 'Rahasia Pembeli', 'rahasia@example.test',
            '44444444-4444-4444-8444-444444444444', 'Private verification details',
            '53217', '53.217', '53,217', '11:22:00',
        ] as $privateValue) {
            $this->assertStringNotContainsString($privateValue, $content);
        }
    }

    public function test_home_bounds_purchase_history_to_the_five_most_recent_eligible_orders(): void
    {
        Carbon::setTestNow('2026-05-16 12:00:00');
        $buyer = User::factory()->create();
        foreach (array_keys(DurationOrder::PACKAGES) as $index => $package) {
            DurationOrder::create([
                'user_id' => $buyer->id,
                'package' => $package,
                'days' => DurationOrder::PACKAGES[$package]['days'],
                'price' => DurationOrder::PACKAGES[$package]['price'],
                'status' => 'approved',
                'approved_at' => now()->subDays(6 - $index),
            ]);
        }

        $html = $this->html($this->get('/')->assertOk()->getContent());
        $items = $html->query('//*[@data-purchase-item]');

        $this->assertCount(5, $items);
        foreach (['12 Bulan', '6 Bulan', '3 Bulan', '1 Bulan', '1 Minggu'] as $index => $label) {
            $this->assertStringContainsString($label, $this->text($items->item($index)->textContent));
        }
    }

    public function test_home_omits_purchase_status_region_without_qualifying_orders(): void
    {
        $empty = $this->html($this->get('/')->assertOk()->getContent());
        $this->assertCount(0, $empty->query('//*[@data-purchase-region]'));

        $buyer = User::factory()->create();
        foreach ([
            ['package' => '1_day', 'price' => 5_000, 'status' => 'pending', 'approved_at' => null],
            ['package' => '1_week', 'price' => 20_000, 'status' => 'rejected', 'approved_at' => now()],
            ['package' => 'manual', 'price' => 0, 'status' => 'approved', 'approved_at' => now()],
        ] as $order) {
            DurationOrder::create([
                'user_id' => $buyer->id,
                'days' => 1,
                'payment_method' => 'qris',
                ...$order,
            ]);
        }

        $response = $this->get('/')->assertOk();
        $html = $this->html($response->getContent());

        $this->assertCount(0, $html->query('//*[@data-purchase-region]'));
    }

    public function test_english_home_localizes_anonymous_purchase_package_and_coarse_date(): void
    {
        Carbon::setTestNow('2026-05-16 12:00:00');
        $buyer = User::factory()->create();
        DurationOrder::create([
            'user_id' => $buyer->id,
            'package' => '1_week',
            'days' => 7,
            'price' => 20_000,
            'payment_method' => 'qris',
            'status' => 'approved',
            'approved_at' => now()->subHours(3),
        ]);

        $html = $this->html($this->get('/en')->assertOk()->getContent());
        $purchase = $html->query('//*[@data-purchase-item]');

        $this->assertCount(1, $purchase);
        $this->assertStringContainsString('1 Week', $this->text($purchase->item(0)->textContent));
        $this->assertStringContainsString('16 May 2026', $this->text($purchase->item(0)->textContent));
    }

    public function test_home_contains_readable_product_prices_and_faqs_without_javascript(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $this->html($response->getContent());
        $headings = $html->query('//main//h1');

        $this->assertCount(1, $headings);
        $this->assertNotSame('', $this->text($headings->item(0)->textContent));
        $this->assertStringContainsString('Platform AI', $this->text($html->evaluate('string(//main)')));

        foreach (config('marketing.models') as $model) {
            $this->assertStringContainsString($model['name'], $this->text($html->evaluate('string(//main)')));
        }

        $this->assertCount(0, $html->query('//*[@data-plan]'));
        $this->assertGreaterThan(0, $html->query('//a[@href="/pricing"]')->length);
        $this->assertCount(0, $html->query('//*[@id="motion-toggle"]'));
        $this->assertCount(1, $html->query('//*[@id="theme-toggle"]'));
        $this->assertCount(1, $html->query('//*[@data-language-toggle]'));
        $this->assertCount(1, $html->query('//*[@id="site-nav"]//*[@class="nav-actions"]//a[@href="/login"]'));
        $this->assertCount(0, $html->query('//*[@id="site-nav"]//*[@class="nav-actions"]//a[@href="/pricing"]'));
        $this->assertStringContainsString('Beyond ordinary.', $this->text($html->evaluate('string(//footer)')));

        $faqs = $html->query('//*[@id="faq"]//details');
        $this->assertCount(count(config('marketing.faqs')), $faqs);

        foreach (config('marketing.faqs') as $index => $faq) {
            $item = $faqs->item($index);
            $this->assertSame($faq['question'], $this->text($html->evaluate('string(.//summary)', $item)));
            $this->assertStringContainsString($faq['answer'], $this->text($item->textContent));
        }

        foreach (['privacy-policy', 'terms-of-service', 'refund-policy'] as $slug) {
            $this->assertGreaterThan(0, $html->query('//a[@href="/'.$slug.'"]')->length);
        }
    }

    public function test_home_preserves_indexable_identity_and_accurate_offers_and_faq_schema(): void
    {
        $response = $this->get('/')->assertOk();
        $html = $this->html($response->getContent());

        $this->assertSame('XSuper.ai — AI-Powered Platform for UMKM Indonesia', $html->evaluate('string(//title)'));
        $this->assertCanonicalAndIndexable($html, 'https://xsuper.dev');
        $this->assertSame('https://xsuper.dev/xsuper-og-v2.png', $html->evaluate('string(//meta[@property="og:image"]/@content)'));
        $this->assertSame('/xsuper-icon-v2.png', $html->evaluate('string(//link[@rel="icon"]/@href)'));

        $graph = $this->structuredData($html)['@graph'];
        $byType = array_column($graph, null, '@type');
        $application = $byType['SoftwareApplication'];

        $this->assertArrayNotHasKey('aggregateRating', $application);
        $this->assertArrayNotHasKey('offers', $application);

        $questions = $byType['FAQPage']['mainEntity'];
        $this->assertCount(count(config('marketing.faqs')), $questions);

        foreach (config('marketing.faqs') as $index => $faq) {
            $this->assertSame($faq['question'], $questions[$index]['name']);
            $this->assertSame($faq['answer'], $questions[$index]['acceptedAnswer']['text']);
        }
    }

    public function test_pricing_is_a_crawlable_dedicated_page_with_accurate_offers(): void
    {
        $response = $this->get('/pricing')->assertOk();
        $html = $this->html($response->getContent());

        $this->assertCanonicalAndIndexable($html, 'https://xsuper.dev/pricing');
        $this->assertCount(1, $html->query('//main//h1'));
        $this->assertCount(count(DurationOrder::PACKAGES), $html->query('//*[@data-plan]'));

        foreach (DurationOrder::PACKAGES as $id => $package) {
            $cards = $html->query('//*[@data-plan="'.$id.'"]');
            $this->assertCount(1, $cards);
            $this->assertStringContainsString($package['label'], $this->text($cards->item(0)->textContent));
            $this->assertStringContainsString('Rp '.number_format($package['price'], 0, ',', '.'), $this->text($cards->item(0)->textContent));
            $this->assertGreaterThan(0, $html->query('.//a[starts-with(@href, "https://wa.me/6287786866648")]', $cards->item(0))->length);
        }

        $graph = $this->structuredData($html)['@graph'];
        $application = array_column($graph, null, '@type')['SoftwareApplication'];
        $offers = array_column($application['offers'], null, 'sku');
        $this->assertSame(array_keys(DurationOrder::PACKAGES), array_keys($offers));
        foreach (DurationOrder::PACKAGES as $id => $package) {
            $this->assertSame(DurationPackagePrice::catalog()[$id]['price_idr'], $offers[$id]['price']);
            $this->assertSame('IDR', $offers[$id]['priceCurrency']);
            $this->assertSame('https://xsuper.dev/pricing', $offers[$id]['url']);
        }
    }

    public function test_english_subscription_pricing_uses_independent_usd_prices(): void
    {
        $response = $this->get('/en/pricing')->assertOk();
        $html = $this->html($response->getContent());

        foreach (array_keys(DurationOrder::PACKAGES) as $id) {
            $cards = $html->query('//*[@data-plan="'.$id.'"]');
            $this->assertCount(1, $cards);
            $this->assertStringContainsString('$'.number_format(DurationPackagePrice::catalog()[$id]['price_usd'], 2, '.', ','), $this->text($cards->item(0)->textContent));
            $this->assertStringNotContainsString('Rp ', $this->text($cards->item(0)->textContent));
        }
        $offers = $this->structuredData($html)['@graph'][0]['offers'];
        foreach ($offers as $offer) {
            $this->assertSame('USD', $offer['priceCurrency']);
        }
        $this->assertCount(0, $html->query('//*[@id="usage-pricing"]'));
    }

    public function test_structured_content_cannot_break_out_of_its_script_element(): void
    {
        $question = 'Apakah teks </script><script id="injected-marketing">alert(1)</script> tetap aman?';
        config(['marketing.faqs.0.question' => $question]);

        $response = $this->get('/')->assertOk();
        $html = $this->html($response->getContent());
        $graph = array_column($this->structuredData($html)['@graph'], null, '@type');

        $this->assertCount(0, $html->query('//script[@id="injected-marketing"]'));
        $this->assertSame($question, $graph['FAQPage']['mainEntity'][0]['name']);
        $this->assertStringContainsString($question, $this->text($html->evaluate('string(//*[@id="faq"])')));
    }

    public function test_legal_pages_have_distinct_readable_policies_and_their_own_canonicals(): void
    {
        $requirements = [
            'privacy-policy' => ['Google', 'alamat IP', 'penghapusan', 'WhatsApp'],
            'terms-of-service' => ['persetujuan admin', 'perangkat', 'kunci API', 'keliru'],
            'refund-policy' => ['24 jam', 'belum dipakai', 'refund penuh', 'pro-rata'],
        ];
        $titles = [];
        $bodies = [];

        foreach ($requirements as $slug => $terms) {
            $response = $this->get('/'.$slug)->assertOk();
            $html = $this->html($response->getContent());
            $this->assertCanonicalAndIndexable($html, 'https://xsuper.dev/'.$slug);
            $this->assertCount(1, $html->query('//main//h1'));
            $this->assertCount(0, $html->query('//script[@type="application/ld+json"]'));

            $titles[] = $this->text($html->evaluate('string(//main//h1)'));
            $body = $this->text($html->evaluate('string(//main)'));
            $bodies[] = $body;

            foreach ($terms as $term) {
                $this->assertStringContainsString($term, $body);
            }

            $this->assertGreaterThan(0, $html->query('//main//a[starts-with(@href, "https://wa.me/6287786866648")]')->length);
        }

        $this->assertCount(3, array_unique($titles));
        $this->assertCount(3, array_unique($bodies));
    }

    public function test_unknown_policy_is_not_rendered_as_a_successful_legal_page(): void
    {
        $this->expectException(NotFoundHttpException::class);

        $this->app->make(PublicSiteController::class)->policy(request(), 'missing-policy');
    }

    public function test_english_public_pages_use_subdirectory_routes_and_query_urls_redirect(): void
    {
        $response = $this->get('/en')
            ->assertOk()
            ->assertHeader('Content-Language', 'en');
        $html = $this->html($response->getContent());

        $this->assertSame('en', $html->evaluate('string(/html/@lang)'));
        $this->assertSame('XSuper.ai — AI Platform for Creating, Learning, and Building', $html->evaluate('string(//title)'));
        $this->assertCanonicalAndIndexable($html, 'https://xsuper.dev/en');
        $this->assertSame('https://xsuper.dev', $html->evaluate('string(//link[@rel="alternate" and @hreflang="id"]/@href)'));
        $this->assertSame('https://xsuper.dev/en', $html->evaluate('string(//link[@rel="alternate" and @hreflang="en"]/@href)'));
        $this->assertSame('https://xsuper.dev', $html->evaluate('string(//link[@rel="alternate" and @hreflang="x-default"]/@href)'));
        $this->assertStringContainsString('One space.', $this->text($html->evaluate('string(//main)')));
        $this->assertStringContainsString('Different minds.', $this->text($html->evaluate('string(//*[@data-orbit-stage])')));
        $this->assertStringContainsString('One workspace.', $this->text($html->evaluate('string(//*[@data-orbit-stage])')));
        $this->assertSame('/', $html->evaluate('string(//*[@data-language-toggle]/@href)'));
        $this->assertSame('/en/models', $html->evaluate('string(//*[@id="site-nav"]/a[1]/@href)'));

        $graph = array_column($this->structuredData($html)['@graph'], null, '@type');
        $this->assertSame('What is XSuper.ai?', $graph['FAQPage']['mainEntity'][0]['name']);

        $pricing = $this->html($this->get('/en/pricing')->assertOk()->assertHeader('Content-Language', 'en')->getContent());
        $this->assertCanonicalAndIndexable($pricing, 'https://xsuper.dev/en/pricing');
        $this->assertStringContainsString('Choose your time.', $this->text($pricing->evaluate('string(//main)')));

        $policy = $this->html($this->get('/en/privacy-policy')->assertOk()->getContent());
        $this->assertStringContainsString('Scope', $this->text($policy->evaluate('string(//main)')));
        $this->assertCanonicalAndIndexable($policy, 'https://xsuper.dev/en/privacy-policy');

        $this->get('/?lang=en')->assertRedirect('/en')->assertStatus(301);
        $this->get('/pricing?lang=en')->assertRedirect('/en/pricing')->assertStatus(301);
        $this->get('/en/pricing?lang=id')->assertRedirect('/pricing')->assertStatus(301);
    }

    public function test_public_model_catalog_is_server_rendered_and_separate_from_subscription_pricing(): void
    {
        $provider = AiProviderProfile::create(['slug' => 'published', 'name' => 'Published Provider', 'is_enabled' => true]);
        $profile = AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'published-model', 'display_name' => 'Published Model',
            'category' => 'chat', 'is_enabled' => true, 'is_available' => true,
        ]);
        $models = $this->html($this->get('/models')->assertOk()->getContent());
        $this->assertCanonicalAndIndexable($models, 'https://xsuper.dev/models');
        $this->assertStringContainsString($profile->display_name, $this->text($models->evaluate('string(//*[@data-model-table])')));
        $catalogText = strtolower($this->text($models->evaluate('string(//*[@data-model-table])')));
        $this->assertStringNotContainsString('au'.'to', $catalogText);
        $this->assertStringNotContainsString('eno'.'wx', $catalogText);

        $pricing = $this->html($this->get('/pricing')->assertOk()->getContent());
        $this->assertCount(0, $pricing->query('//*[@id="usage-pricing"]'));
        $this->assertCount(count(DurationOrder::PACKAGES), $pricing->query('//*[@data-plan]'));
        $this->assertCount(1, $pricing->query('//*[@data-pricing-carousel]'));
        $this->assertCount(1, $pricing->query('//*[@data-pricing-prev]'));
        $this->assertCount(1, $pricing->query('//*[@data-pricing-next]'));
    }

    public function test_media_catalog_prices_show_generator_tokens_not_subscription_inclusion(): void
    {
        $provider = AiProviderProfile::create([
            'slug' => 'media-pricing', 'name' => 'Media Provider', 'is_enabled' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'priced-image', 'display_name' => 'Priced Image',
            'category' => 'image', 'token_cost' => 17, 'is_enabled' => true, 'is_available' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'priced-video', 'display_name' => 'Priced Video',
            'category' => 'video', 'token_cost' => 203, 'is_enabled' => true, 'is_available' => true,
        ]);
        AiModelProfile::create([
            'provider_id' => $provider->id, 'model_id' => 'unpriced-image', 'display_name' => 'Unpriced Image',
            'category' => 'image', 'token_cost' => null, 'is_enabled' => true, 'is_available' => true,
        ]);

        $catalog = $this->html($this->get('/en/models')->assertOk()->getContent());
        $image = $this->text($catalog->evaluate('string(//article[@id="priced-image"])'));
        $video = $this->text($catalog->evaluate('string(//article[@id="priced-video"])'));
        $unpriced = $this->text($catalog->evaluate('string(//article[@id="unpriced-image"])'));

        $this->assertStringContainsString('17 tokens / image', $image);
        $this->assertStringContainsString('203 tokens / video', $video);
        $this->assertStringNotContainsString('Included', $unpriced);

        $localized = $this->html($this->get('/models')->assertOk()->getContent());
        $this->assertStringContainsString('17 token / gambar', $this->text($localized->evaluate('string(//article[@id="priced-image"])')));
        $this->assertStringContainsString('203 token / video', $this->text($localized->evaluate('string(//article[@id="priced-video"])')));
    }

    public function test_sitemap_is_valid_xml_and_lists_only_public_canonical_pages(): void
    {
        $response = $this->get('/sitemap.xml')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8');

        $document = new DOMDocument;
        $this->assertTrue($document->loadXML($response->getContent()));
        $xml = new DOMXPath($document);
        $xml->registerNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $urls = [];

        foreach ($xml->query('/s:urlset/s:url/s:loc') as $location) {
            $urls[] = $location->textContent;
        }

        $this->assertSame([
            'https://xsuper.dev',
            'https://xsuper.dev/en',
            'https://xsuper.dev/pricing',
            'https://xsuper.dev/en/pricing',
            'https://xsuper.dev/models',
            'https://xsuper.dev/en/models',
            'https://xsuper.dev/privacy-policy',
            'https://xsuper.dev/en/privacy-policy',
            'https://xsuper.dev/terms-of-service',
            'https://xsuper.dev/en/terms-of-service',
            'https://xsuper.dev/refund-policy',
            'https://xsuper.dev/en/refund-policy',
        ], $urls);
    }

    public function test_login_keeps_the_react_application_entry_instead_of_public_content(): void
    {
        $response = $this->get('/login')->assertOk();
        $html = $this->html($response->getContent());

        $this->assertCount(1, $html->query('//div[@id="app"]'));
        $this->assertCount(0, $html->query('//*[@data-plan]'));
        $this->assertSame('noindex, follow', $html->evaluate('string(//meta[@name="robots"]/@content)'));
        $this->assertSame('https://xsuper.dev/login', $html->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertCount(0, $html->query('//script[@type="application/ld+json"]'));
    }

    private function html(string $content): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$content, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return new DOMXPath($document);
    }

    private function text(string $content): string
    {
        return trim(preg_replace('/\s+/u', ' ', $content));
    }

    private function assertCanonicalAndIndexable(DOMXPath $html, string $canonical): void
    {
        $this->assertCount(1, $html->query('//link[@rel="canonical"]'));
        $this->assertSame($canonical, $html->evaluate('string(//link[@rel="canonical"]/@href)'));
        $robots = array_map('trim', explode(',', $html->evaluate('string(//meta[@name="robots"]/@content)')));
        $this->assertContains('index', $robots);
        $this->assertContains('follow', $robots);
        $this->assertNotContains('noindex', $robots);
    }

    private function structuredData(DOMXPath $html): array
    {
        $scripts = $html->query('//script[@type="application/ld+json"]');
        $this->assertCount(1, $scripts);

        return json_decode($scripts->item(0)->textContent, true, 512, JSON_THROW_ON_ERROR);
    }
}
