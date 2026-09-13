<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicSiteController;
use App\Models\DurationOrder;
use App\Models\DurationPackagePrice;
use DOMDocument;
use DOMXPath;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.umami.id' => null]);
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

        $this->assertSame('UltrAI — AI-Powered Platform for UMKM Indonesia', $html->evaluate('string(//title)'));
        $this->assertCanonicalAndIndexable($html, 'https://ultrai.id');
        $this->assertSame('https://ultrai.id/og-image.png', $html->evaluate('string(//meta[@property="og:image"]/@content)'));
        $this->assertSame('/ultr-icons.png', $html->evaluate('string(//link[@rel="icon"]/@href)'));

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

        $this->assertCanonicalAndIndexable($html, 'https://ultrai.id/pricing');
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
            $this->assertSame('https://ultrai.id/pricing', $offers[$id]['url']);
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
            $this->assertCanonicalAndIndexable($html, 'https://ultrai.id/'.$slug);
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
        $this->assertSame('UltrAI — AI Platform for Creating, Learning, and Building', $html->evaluate('string(//title)'));
        $this->assertCanonicalAndIndexable($html, 'https://ultrai.id/en');
        $this->assertSame('https://ultrai.id', $html->evaluate('string(//link[@rel="alternate" and @hreflang="id"]/@href)'));
        $this->assertSame('https://ultrai.id/en', $html->evaluate('string(//link[@rel="alternate" and @hreflang="en"]/@href)'));
        $this->assertSame('https://ultrai.id', $html->evaluate('string(//link[@rel="alternate" and @hreflang="x-default"]/@href)'));
        $this->assertStringContainsString('One space.', $this->text($html->evaluate('string(//main)')));
        $this->assertStringContainsString('Different minds.', $this->text($html->evaluate('string(//*[@data-orbit-stage])')));
        $this->assertStringContainsString('One workspace.', $this->text($html->evaluate('string(//*[@data-orbit-stage])')));
        $this->assertSame('/', $html->evaluate('string(//*[@data-language-toggle]/@href)'));
        $this->assertSame('/en/models', $html->evaluate('string(//*[@id="site-nav"]/a[1]/@href)'));

        $graph = array_column($this->structuredData($html)['@graph'], null, '@type');
        $this->assertSame('What is UltrAI?', $graph['FAQPage']['mainEntity'][0]['name']);

        $pricing = $this->html($this->get('/en/pricing')->assertOk()->assertHeader('Content-Language', 'en')->getContent());
        $this->assertCanonicalAndIndexable($pricing, 'https://ultrai.id/en/pricing');
        $this->assertStringContainsString('Choose your time.', $this->text($pricing->evaluate('string(//main)')));

        $policy = $this->html($this->get('/en/privacy-policy')->assertOk()->getContent());
        $this->assertStringContainsString('Scope', $this->text($policy->evaluate('string(//main)')));
        $this->assertCanonicalAndIndexable($policy, 'https://ultrai.id/en/privacy-policy');

        $this->get('/?lang=en')->assertRedirect('/en')->assertStatus(301);
        $this->get('/pricing?lang=en')->assertRedirect('/en/pricing')->assertStatus(301);
        $this->get('/en/pricing?lang=id')->assertRedirect('/pricing')->assertStatus(301);
    }

    public function test_public_model_catalog_is_server_rendered_and_separate_from_subscription_pricing(): void
    {
        $models = $this->html($this->get('/models')->assertOk()->getContent());
        $this->assertCanonicalAndIndexable($models, 'https://ultrai.id/models');
        $this->assertStringContainsString('Katalog Model AI', $this->text($models->evaluate('string(//main)')));
        $this->assertGreaterThanOrEqual(count(config('marketing.models')), $models->query('//*[@data-model-card]')->count());
        $this->assertCount(1, $models->query('//*[@data-model-search]'));
        $this->assertGreaterThan(0, $models->query('//*[@data-model-provider-check]')->count());
        $this->assertCount(1, $models->query('//*[@data-model-filter-sidebar]'));
        $this->assertCount(1, $models->query('//*[@data-model-table]'));
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
            'https://ultrai.id',
            'https://ultrai.id/en',
            'https://ultrai.id/pricing',
            'https://ultrai.id/en/pricing',
            'https://ultrai.id/models',
            'https://ultrai.id/en/models',
            'https://ultrai.id/privacy-policy',
            'https://ultrai.id/en/privacy-policy',
            'https://ultrai.id/terms-of-service',
            'https://ultrai.id/en/terms-of-service',
            'https://ultrai.id/refund-policy',
            'https://ultrai.id/en/refund-policy',
        ], $urls);
    }

    public function test_login_keeps_the_react_application_entry_instead_of_public_content(): void
    {
        $response = $this->get('/login')->assertOk();
        $html = $this->html($response->getContent());

        $this->assertCount(1, $html->query('//div[@id="app"]'));
        $this->assertCount(0, $html->query('//*[@data-plan]'));
        $this->assertSame('noindex, follow', $html->evaluate('string(//meta[@name="robots"]/@content)'));
        $this->assertSame('https://ultrai.id/login', $html->evaluate('string(//link[@rel="canonical"]/@href)'));
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
