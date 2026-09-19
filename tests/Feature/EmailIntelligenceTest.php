<?php

namespace Tests\Feature;

use App\Services\EmailIntelligence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private function service(array $mx = []): EmailIntelligence
    {
        return new EmailIntelligence(fn (string $domain): array => $mx[$domain] ?? []);
    }

    public function test_disposable_domains_and_their_subdomains_are_detected(): void
    {
        $service = $this->service();

        $this->assertTrue($service->isDisposable('abuse@mailinator.com'));
        $this->assertTrue($service->isDisposable('x@relay.mailinator.com'));
        $this->assertTrue($service->isDisposable('y@yopmail.com'));
        $this->assertFalse($service->isDisposable('real@company.co.id'));
        $this->assertFalse($service->isDisposable('person@gmail.com'));
    }

    public function test_provider_distinguishes_gmail_workspace_and_other(): void
    {
        $service = $this->service([
            'studio.co.id' => ['aspmx.l.google.com', 'alt1.aspmx.l.google.com'],
            'selfhosted.io' => ['mx1.selfhosted.io'],
        ]);

        $this->assertSame('gmail', $service->provider('me@gmail.com'));
        $this->assertSame('google_workspace', $service->provider('team@studio.co.id'));
        $this->assertTrue($service->isGoogleWorkspace('team@studio.co.id'));
        $this->assertFalse($service->isGoogleWorkspace('me@gmail.com'));
        $this->assertSame('other', $service->provider('admin@selfhosted.io'));
        $this->assertSame('disposable', $service->provider('temp@mailinator.com'));
    }

    public function test_mx_lookup_failure_falls_back_to_other_and_never_throws(): void
    {
        $service = $this->service([]); // resolver returns no hosts for every domain

        $this->assertSame('other', $service->provider('someone@unknown-domain.test'));
        $this->assertFalse($service->isGoogleWorkspace('someone@unknown-domain.test'));
    }

    public function test_registration_blocks_disposable_email_and_records_provider(): void
    {
        $this->postJson('/register', [
            'name' => 'Throwaway',
            'email' => 'throwaway@mailinator.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseMissing('users', ['email' => 'throwaway@mailinator.com']);

        $this->postJson('/register', [
            'name' => 'Real Person',
            'email' => 'real.person@gmail.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'real.person@gmail.com', 'email_provider' => 'gmail', 'role' => 'member']);
    }

    public function test_disposable_gate_can_be_disabled_by_config(): void
    {
        config(['email_intelligence.block_disposable' => false]);

        $this->postJson('/register', [
            'name' => 'Temp Ok',
            'email' => 'temp.ok@guerrillamail.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();
        $this->assertDatabaseHas('users', ['email' => 'temp.ok@guerrillamail.com', 'email_provider' => 'disposable']);
    }
}
