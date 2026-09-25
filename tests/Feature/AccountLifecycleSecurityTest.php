<?php

namespace Tests\Feature;

use App\Mail\EmailOtp;
use App\Models\User;
use App\Services\EmailIntelligence;
use App\Services\EmailOtpService;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountLifecycleSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database', 'session.encrypt' => false]);
        $this->app->instance(EmailIntelligence::class, new EmailIntelligence(fn () => []));
        Mail::fake();
    }

    public static function profileEndpoints(): array
    {
        return [['/api/u/p'], ['/user/profile-information']];
    }

    public static function passwordEndpoints(): array
    {
        return [['/api/u/pw'], ['/user/password']];
    }

    #[DataProvider('profileEndpoints')]
    public function test_an_email_change_removes_verification_and_refreshes_provider_metadata(string $endpoint): void
    {
        $user = $this->member('verified');
        $cookies = [];
        $this->login($cookies, $user);

        $this->http($cookies, 'PUT', $endpoint, ['name' => 'Changed', 'email' => 'changed@outlook.com'])->assertOk();

        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('other', $user->fresh()->email_provider);
        $this->http($cookies, 'GET', '/api/dashboard')->assertForbidden()->assertJsonPath('email_unverified', true);
        $this->http($cookies, 'POST', '/api/u/verify-email/send')->assertOk();
        $mail = Mail::sent(EmailOtp::class)->last();
        $this->assertTrue($mail->hasTo('changed@outlook.com'));
        $this->http($cookies, 'POST', '/api/u/verify-email', ['code' => $mail->code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('profileEndpoints')]
    public function test_a_code_for_the_previous_address_cannot_activate_the_new_address(string $endpoint): void
    {
        $user = $this->member('pending', false);
        $cookies = [];
        $this->login($cookies, $user);
        app(EmailOtpService::class)->send($user);
        $oldCode = Mail::sent(EmailOtp::class)->last()->code;

        $this->http($cookies, 'PUT', $endpoint, ['name' => 'Changed', 'email' => 'pending-new@gmail.com'])->assertOk();
        $this->http($cookies, 'POST', '/api/u/verify-email', ['code' => $oldCode])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->email_otp_hash);
        $this->assertNull($user->fresh()->email_otp_sent_at);
        $this->assertNull($user->fresh()->email_otp_expires_at);
        $this->assertSame(0, $user->fresh()->email_otp_attempts);

        // Changing the recipient must not inherit the old recipient's resend cooldown.
        $this->http($cookies, 'POST', '/api/u/verify-email/send')->assertOk();
        $mail = Mail::sent(EmailOtp::class)->last();
        $this->assertTrue($mail->hasTo('pending-new@gmail.com'));
        $this->http($cookies, 'POST', '/api/u/verify-email', ['code' => $mail->code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_name_only_profile_edits_preserve_the_code_without_disclosing_authentication_internals(): void
    {
        $user = $this->member('projection', false);
        $cookies = [];
        $this->login($cookies, $user);
        app(EmailOtpService::class)->send($user);
        $code = Mail::sent(EmailOtp::class)->last()->code;
        $user->forceFill([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_recovery_codes' => encrypt(json_encode(['secret-recovery-code'])),
            'two_factor_confirmed_at' => now(),
        ])->save();

        $response = $this->http($cookies, 'PUT', '/api/u/p', ['name' => 'Safe name', 'email' => $user->email]);
        $response->assertOk()->assertJsonPath('user.name', 'Safe name')->assertJsonPath('user.email_verified', false);
        foreach (['password', 'remember_token', 'email_otp_hash', 'email_otp_expires_at', 'email_otp_sent_at', 'email_otp_attempts', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $field) {
            $response->assertJsonMissingPath('user.'.$field);
            $this->assertArrayNotHasKey($field, $user->fresh()->toArray());
        }
        $this->http($cookies, 'POST', '/api/u/verify-email', ['code' => $code])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_stale_email_editor_clears_proof_written_by_a_concurrent_request(): void
    {
        foreach ([false, true] as $verifyBeforeEdit) {
            $user = $this->member('stale-'.(int) $verifyBeforeEdit, false);
            $staleEditor = $user->fresh();
            app(EmailOtpService::class)->send($user);
            if ($verifyBeforeEdit) {
                app(EmailOtpService::class)->verify($user, Mail::sent(EmailOtp::class)->last()->code);
            }

            $staleEditor->update(['email' => 'stale-new-'.(int) $verifyBeforeEdit.'@outlook.com']);

            $this->assertNull($user->fresh()->email_verified_at);
            $this->assertNull($user->fresh()->email_otp_hash);
            $this->assertNull($user->fresh()->email_otp_sent_at);
            $this->assertSame(0, $user->fresh()->email_otp_attempts);
        }
    }

    public function test_a_concurrent_address_change_between_hash_check_and_consumption_cannot_be_verified(): void
    {
        $user = $this->member('consume-race', false);
        app(EmailOtpService::class)->send($user);
        $code = Mail::sent(EmailOtp::class)->last()->code;
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use ($user, &$armed): void {
            if ($armed && str_starts_with(strtolower($query), 'update') && str_contains($query, 'email_verified_at')) {
                $armed = false;
                $user->fresh()->update(['email' => 'concurrent-new@outlook.com']);
            }
        });

        try {
            app(EmailOtpService::class)->verify($user, $code);
            $this->fail('A code for the previous email was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame('concurrent-new@outlook.com', $user->fresh()->email);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_consumption_cannot_clear_or_accept_a_concurrently_reissued_code(): void
    {
        $user = $this->member('resend-race', false);
        app(EmailOtpService::class)->send($user);
        $oldCode = Mail::sent(EmailOtp::class)->last()->code;
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use ($user, &$armed): void {
            if ($armed && str_starts_with(strtolower($query), 'update') && str_contains($query, 'email_verified_at')) {
                $armed = false;
                $user->fresh()->forceFill(['email_otp_sent_at' => now()->subMinutes(2)])->save();
                app(EmailOtpService::class)->send($user);
            }
        });

        try {
            app(EmailOtpService::class)->verify($user, $oldCode);
            $this->fail('A superseded code was consumed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertNull($user->fresh()->email_verified_at);
        app(EmailOtpService::class)->verify($user, Mail::sent(EmailOtp::class)->last()->code);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_failed_attempt_cannot_charge_a_newer_code_version(): void
    {
        $user = $this->member('attempt-race', false);
        app(EmailOtpService::class)->send($user);
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use ($user, &$armed): void {
            if ($armed && str_starts_with(strtolower($query), 'update') && str_contains($query, 'email_otp_attempts')) {
                $armed = false;
                $user->fresh()->forceFill(['email_otp_sent_at' => now()->subMinutes(2)])->save();
                app(EmailOtpService::class)->send($user);
            }
        });

        try {
            app(EmailOtpService::class)->verify($user, '000000');
            $this->fail('An incorrect code was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        $this->assertSame(0, $user->fresh()->email_otp_attempts);
        app(EmailOtpService::class)->verify($user, Mail::sent(EmailOtp::class)->last()->code);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_a_concurrent_address_change_prevents_storing_a_code_for_the_old_recipient(): void
    {
        $user = $this->member('send-race', false);
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query) use ($user, &$armed): void {
            if ($armed && str_starts_with(strtolower($query), 'update') && str_contains($query, 'email_otp_hash')) {
                $armed = false;
                $user->fresh()->update(['email' => 'send-new@outlook.com']);
            }
        });

        try {
            app(EmailOtpService::class)->send($user);
            $this->fail('A code was attached after its recipient changed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('code', $exception->errors());
        }
        Mail::assertNothingSent();
        $this->assertNull($user->fresh()->email_otp_hash);
        app(EmailOtpService::class)->send($user);
        $mail = Mail::sent(EmailOtp::class)->last();
        $this->assertTrue($mail->hasTo('send-new@outlook.com'));
        app(EmailOtpService::class)->verify($user, $mail->code);
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    #[DataProvider('passwordEndpoints')]
    public function test_password_changes_revoke_independent_session_and_remember_cookies_but_keep_the_changer(string $endpoint): void
    {
        $user = $this->member('password-change');
        $otherUser = $this->member('unrelated');
        $stolen = $current = $unrelated = [];
        $this->login($stolen, $user);
        $this->login($current, $user);
        $this->login($unrelated, $otherUser);
        $oldCurrent = $current;
        $oldRemember = $this->rememberOnly($stolen);
        $oldToken = $user->fresh()->remember_token;
        $oldRow = DB::table('sessions')->where('user_id', $user->id)->first();

        $this->http($current, 'PUT', $endpoint, [
            'current_password' => 'OldSecret123!', 'password' => 'NewSecret456!', 'password_confirmation' => 'NewSecret456!',
        ])->assertOk();

        $this->assertSame(1, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->http($stolen, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($oldCurrent, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($oldRemember, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($current, 'GET', '/api/u/me')->assertOk()->assertJsonPath('email', $user->email);
        $this->http($unrelated, 'GET', '/api/u/me')->assertOk()->assertJsonPath('email', $otherUser->email);
        $currentRemember = $this->rememberOnly($current);
        $this->http($currentRemember, 'GET', '/api/u/me')->assertOk()->assertJsonPath('email', $user->email);

        // A request that began before revocation must not resurrect an authenticated old session.
        DB::table('sessions')->updateOrInsert(['id' => $oldRow->id], (array) $oldRow);
        $resurrected = $this->sessionCookie($oldRow->id);
        $this->http($resurrected, 'GET', '/api/u/me')->assertUnauthorized();
    }

    public function test_recovery_revokes_every_existing_session_even_if_a_stale_row_is_written_back(): void
    {
        $user = $this->member('recovery');
        $first = $second = $recovery = [];
        $this->login($first, $user);
        $this->login($second, $user);
        $remember = $this->rememberOnly($first);
        $oldRow = DB::table('sessions')->where('user_id', $user->id)->first();
        $token = Password::broker()->createToken($user);

        $this->http($recovery, 'POST', '/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewSecret456!', 'password_confirmation' => 'NewSecret456!',
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->http($first, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($second, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($remember, 'GET', '/api/u/me')->assertUnauthorized();
        DB::table('sessions')->updateOrInsert(['id' => $oldRow->id], (array) $oldRow);
        $resurrected = $this->sessionCookie($oldRow->id);
        $this->http($resurrected, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($recovery, 'POST', '/api/login', ['email' => $user->email, 'password' => 'NewSecret456!'])
            ->assertOk()->assertJsonPath('user.id', $user->id);
        $this->http($recovery, 'GET', '/api/u/me')->assertOk();
    }

    public function test_an_admin_reset_revokes_a_separate_targets_legacy_session_without_switching_the_admin_identity(): void
    {
        $admin = $this->member('reset-admin');
        $admin->update(['role' => 'admin']);
        $target = $this->member('reset-target');
        $unrelated = $this->member('reset-unrelated');
        $adminCookies = $targetCookies = $unrelatedCookies = [];
        $this->login($adminCookies, $admin);
        $this->login($targetCookies, $target);
        $this->login($unrelatedCookies, $unrelated);
        $oldRemember = $this->rememberOnly($targetCookies);
        $targetCookies = array_diff_key($targetCookies, $oldRemember);
        $oldToken = $target->fresh()->remember_token;

        // Preserve the genuine login cookie, but model a legacy persisted session
        // created before the guard recorded a password hash at login.
        $row = DB::table('sessions')->where('user_id', $target->id)->sole();
        $payload = json_decode(base64_decode($row->payload), true, 512, JSON_THROW_ON_ERROR);
        unset($payload['password_hash_web']);
        DB::table('sessions')->where('id', $row->id)->update([
            'payload' => base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ]);

        $this->http($adminCookies, 'PUT', '/api/a/u/'.$target->id, [
            'name' => 'Admin changed target', 'email' => $target->email, 'role' => 'member',
            'password' => 'NewSecret456!', 'duration' => '7d',
            'permissions' => ['chat' => true, 'video_generator' => true],
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $target->id)->count());
        $this->assertNotSame($oldToken, $target->fresh()->remember_token);
        $this->assertSame('Admin changed target', $target->fresh()->name);
        $this->assertSame(['chat' => true, 'video_generator' => true], $target->fresh()->permissions);
        $this->assertTrue($target->fresh()->expires_at->isFuture());
        $this->http($targetCookies, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($oldRemember, 'GET', '/api/u/me')->assertUnauthorized();
        $this->http($adminCookies, 'GET', '/api/user')->assertOk()->assertJsonPath('id', $admin->id);
        $this->http($unrelatedCookies, 'GET', '/api/u/me')->assertOk()->assertJsonPath('email', $unrelated->email);
        $this->http($targetCookies, 'POST', '/api/login', [
            'email' => $target->email, 'password' => 'NewSecret456!',
        ])->assertOk()->assertJsonPath('user.id', $target->id);
    }

    public function test_an_admin_reset_rolls_back_profile_and_password_changes_when_session_revocation_fails(): void
    {
        $admin = $this->member('rollback-admin');
        $admin->update(['role' => 'admin']);
        $target = $this->member('rollback-target');
        $adminCookies = $targetCookies = [];
        $this->login($adminCookies, $admin);
        $this->login($targetCookies, $target);
        $before = $target->fresh();
        $armed = true;
        DB::connection()->beforeExecuting(function (string $query, array $bindings) use ($target, &$armed): void {
            if ($armed && str_starts_with(strtolower($query), 'delete')
                && str_contains($query, 'sessions') && in_array($target->id, $bindings, true)) {
                $armed = false;
                throw new \RuntimeException('Isolated session-revocation failure.');
            }
        });

        $this->http($adminCookies, 'PUT', '/api/a/u/'.$target->id, [
            'name' => 'Must roll back', 'email' => 'rollback-new@gmail.com', 'role' => 'member',
            'password' => 'NewSecret456!', 'duration' => '30d', 'permissions' => ['chat' => false],
        ])->assertStatus(500);

        $after = $target->fresh();
        foreach (['name', 'email', 'password', 'remember_token', 'permissions'] as $field) {
            $this->assertSame($before->{$field}, $after->{$field});
        }
        $this->assertEquals($before->expires_at, $after->expires_at);
        $this->assertEquals($before->email_verified_at, $after->email_verified_at);
        $this->http($targetCookies, 'GET', '/api/u/me')->assertOk()->assertJsonPath('email', $before->email);
        $this->http($adminCookies, 'GET', '/api/user')->assertOk()->assertJsonPath('id', $admin->id);
    }

    #[DataProvider('passwordEndpoints')]
    public function test_an_incorrect_current_password_does_not_revoke_valid_sessions(string $endpoint): void
    {
        $user = $this->member('bad-password');
        $first = $second = [];
        $this->login($first, $user);
        $this->login($second, $user);
        $token = $user->fresh()->remember_token;

        $this->http($second, 'PUT', $endpoint, [
            'current_password' => 'incorrect', 'password' => 'NewSecret456!', 'password_confirmation' => 'NewSecret456!',
        ])->assertUnprocessable();
        $this->assertSame($token, $user->fresh()->remember_token);
        $this->http($first, 'GET', '/api/u/me')->assertOk();
        $this->http($second, 'GET', '/api/u/me')->assertOk();
    }

    private function member(string $name, bool $verified = true): User
    {
        return User::factory()->create([
            'name' => $name, 'email' => $name.'@gmail.com', 'password' => 'OldSecret123!',
            'email_provider' => 'gmail', 'email_verified_at' => $verified ? now() : null,
        ]);
    }

    private function login(array &$cookies, User $user): void
    {
        $this->http($cookies, 'POST', '/api/login', [
            'email' => $user->email, 'password' => 'OldSecret123!', 'remember' => true,
        ])->assertOk()->assertJsonPath('user.id', $user->id);
    }

    private function rememberOnly(array $cookies): array
    {
        return array_filter($cookies, fn (string $name) => str_starts_with($name, 'remember_web_'), ARRAY_FILTER_USE_KEY);
    }

    private function sessionCookie(string $id): array
    {
        $name = config('session.cookie');
        $encrypter = app('encrypter');

        return [$name => $encrypter->encrypt(CookieValuePrefix::create($name, $encrypter->getKey()).$id, false)];
    }

    /** Each jar sends the encrypted response cookies through the real middleware, never actingAs. */
    private function http(array &$cookies, string $method, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        foreach ($this->app['cookie']->getQueuedCookies() as $cookie) {
            $this->app['cookie']->unqueue($cookie->getName(), $cookie->getPath());
        }

        $request = Request::create('http://localhost'.$path, $method, [], $cookies, [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'AccountLifecycleSecurityTest',
        ], json_encode($data));
        $kernel = $this->app->make(Kernel::class);
        $response = $kernel->handle($request);
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
                unset($cookies[$cookie->getName()]);
            } else {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }
        $kernel->terminate($request, $response);

        return TestResponse::fromBaseResponse($response);
    }
}
