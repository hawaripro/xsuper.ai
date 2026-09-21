<?php

namespace Tests\Feature\Media;

use App\Services\AiProviderEndpoint;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The loopback provider affordance is double-gated: it works ONLY when APP_ENV=local AND
 * media.allow_local_providers is true, and only for loopback hosts. Any non-local environment
 * (production, testing) keeps the strict public-HTTPS SSRF guard, so a mock endpoint can never
 * be reached in production.
 */
class LocalProviderGuardTest extends TestCase
{
    private function endpoint(): AiProviderEndpoint
    {
        return app(AiProviderEndpoint::class);
    }

    public function test_non_local_environment_rejects_loopback_even_with_flag(): void
    {
        // Test env is "testing" (not "local"), so the flag alone must not open the guard.
        config(['media.allow_local_providers' => true]);

        $this->expectException(InvalidArgumentException::class);
        $this->endpoint()->normalize('http://127.0.0.1:9000/api/v1', 'kinovi');
    }

    public function test_local_environment_with_flag_allows_loopback(): void
    {
        $this->app['env'] = 'local';
        config(['media.allow_local_providers' => true]);

        $this->assertSame('http://127.0.0.1:9000/api/v1', $this->endpoint()->normalize('http://127.0.0.1:9000/api/v1', 'kinovi'));
        $this->assertTrue($this->endpoint()->allowsLoopback('127.0.0.1'));
        $this->assertEquals(['allow_redirects' => false, 'proxy' => '', 'verify' => false], $this->endpoint()->requestOptions('http://127.0.0.1:9000/api/v1'));
    }

    public function test_local_flag_off_rejects_loopback(): void
    {
        $this->app['env'] = 'local';
        config(['media.allow_local_providers' => false]);

        $this->assertFalse($this->endpoint()->allowsLoopback('127.0.0.1'));
        $this->expectException(InvalidArgumentException::class);
        $this->endpoint()->normalize('http://127.0.0.1:9000/api/v1', 'kinovi');
    }

    public function test_local_affordance_is_loopback_only_not_blanket_http(): void
    {
        $this->app['env'] = 'local';
        config(['media.allow_local_providers' => true]);

        // A public host over http still fails the strict https guard — the affordance is loopback-scoped.
        $this->expectException(InvalidArgumentException::class);
        $this->endpoint()->normalize('http://example.com/v1', 'kinovi');
    }
}
