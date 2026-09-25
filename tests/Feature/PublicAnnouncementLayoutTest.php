<?php

namespace Tests\Feature;

use App\Models\ContentBlock;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicAnnouncementLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config(['services.umami.id' => null]);
    }

    public function test_published_announcement_sits_above_the_header_in_one_sticky_stack(): void
    {
        $this->publishAnnouncement('Pemeliharaan malam ini', ['landing', 'pricing', 'models']);

        foreach (['/', '/pricing', '/models'] as $path) {
            $html = $this->html($this->get($path)->assertOk()->getContent());

            $this->assertSame('skip-link', $html->evaluate('string(//body/*[1]/@class)'), $path);
            $stack = $this->stack($html, $path);
            $this->assertSame(['aside', 'header'], array_map(fn (DOMElement $element) => $element->nodeName, $stack), $path);
            $this->assertSame('site-announcement', $stack[0]->getAttribute('class'), $path);
            $this->assertStringContainsString('Pemeliharaan malam ini', $stack[0]->textContent, $path);
            $this->assertSame('site-header', $stack[1]->getAttribute('class'), $path);
            $this->assertCount(1, $html->query('//*[@class="site-announcement"]'), $path);
        }
    }

    public function test_policy_pages_keep_the_header_stack_without_the_announcement(): void
    {
        $this->publishAnnouncement('Pemeliharaan malam ini', ['dashboard', 'landing', 'pricing', 'models']);

        $html = $this->html($this->get('/privacy-policy')->assertOk()->getContent());

        $this->assertSame(['header'], array_map(fn (DOMElement $element) => $element->nodeName, $this->stack($html, '/privacy-policy')));
        $this->assertCount(0, $html->query('//*[@class="site-announcement"]'));
    }

    private function publishAnnouncement(string $message, array $surfaces): void
    {
        $payload = ['message' => $message, 'level' => 'warning', 'surfaces' => $surfaces];

        ContentBlock::create([
            'key' => 'system.announcement',
            'locale' => 'id',
            'draft' => $payload,
            'published' => $payload,
            'is_published' => true,
            'published_at' => now(),
        ]);
    }

    /** @return list<DOMElement> */
    private function stack(DOMXPath $html, string $path): array
    {
        $stacks = $html->query('//body/*[@data-site-top]');
        $this->assertCount(1, $stacks, $path);

        return $this->elementChildren($stacks->item(0));
    }

    /** @return list<DOMElement> */
    private function elementChildren(DOMNode $node): array
    {
        $children = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
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
}
