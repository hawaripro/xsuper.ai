<?php

namespace Tests\Feature;

use App\Mail\EmailOtp;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginAdmissionSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database']);
        Mail::fake();
        Notification::fake();
    }

    public function test_google_requires_the_confirmed_local_factor_before_issuing_an_admin_session(): void
    {
        $admin = $this->factorUser(['role' => 'admin', 'google_id' => 'google-subject']);
        $this->google($admin->email);
        SecuritySetting::current()->update(['require_admin_2fa' => true]);
        $cookies = [];

        $this->http($cookies, 'GET', '/auth/google/callback')->assertRedirect('/login?two_factor=1');
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
        $this->http($cookies, 'GET', '/api/security/settings')->assertUnauthorized();
        $this->http($cookies, 'POST', '/api/login/two-factor', ['recovery_code' => 'incorrect'])
            ->assertUnprocessable();
        $this->http($cookies, 'POST', '/api/login/two-factor', ['recovery_code' => 'login-admission-recovery'])
            ->assertOk()->assertJsonPath('user.id', $admin->id);
        $this->http($cookies, 'GET', '/api/security/settings')->assertOk();
    }

    public function test_google_refuses_an_unverified_preregistered_identity_without_blocking_password_recovery(): void
    {
        $attacker = [];
        $victim = [];
        $this->http($attacker, 'POST', '/register', [
            'name' => 'Unproven identity', 'email' => 'victim@example.com',
            'password' => 'Secret123!', 'password_confirmation' => 'Secret123!',
        ])->assertCreated();
        $user = User::where('email', 'victim@example.com')->sole();
        $this->google($user->email);

        $this->http($victim, 'GET', '/auth/google/callback')->assertRedirect('/login?error=account_link_required');
        $this->http($victim, 'GET', '/api/user')->assertUnauthorized();
        $this->assertNull($user->fresh()->google_id);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->http($attacker, 'GET', '/api/dashboard')->assertForbidden()->assertJsonPath('email_unverified', true);
        $this->http($victim, 'POST', '/forgot-password', ['email' => $user->email])->assertOk();
    }

    public function test_email_only_linking_also_requires_a_verified_provider_address(): void
    {
        $user = $this->member();
        $this->google($user->email, false);
        $cookies = [];
        $this->http($cookies, 'GET', '/auth/google/callback')->assertRedirect('/login?error=account_link_required');
        $this->assertNull($user->fresh()->google_id);
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
    }

    #[DataProvider('initialAdmissionCases')]
    public function test_password_login_denies_disabled_accounts_and_disallowed_admin_networks(string $path, string $policy): void
    {
        $user = $this->member($policy === 'ip' ? ['role' => 'admin'] : ['is_active' => false]);
        if ($policy === 'ip') {
            SecuritySetting::current()->update(['enforce_admin_ip' => true, 'admin_ip_allowlist' => ['10.0.0.0/8']]);
        }
        $cookies = [];
        $this->http($cookies, 'POST', $path, ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertForbidden()->assertJsonPath('code', $policy === 'ip' ? 'ip_not_allowed' : 'account_disabled');
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
    }

    public static function initialAdmissionCases(): array
    {
        return [
            'native inactive' => ['/login', 'inactive'], 'native network' => ['/login', 'ip'],
            'custom inactive' => ['/api/login', 'inactive'], 'custom network' => ['/api/login', 'ip'],
        ];
    }

    #[DataProvider('challengeAdmissionCases')]
    public function test_pending_challenges_recheck_account_network_and_device_policy(string $login, string $challenge, string $policy): void
    {
        $user = $this->factorUser($policy === 'ip' ? ['role' => 'admin'] : []);
        if (in_array($policy, ['blocked', 'pending'], true)) {
            $this->knownDevice($user);
        }
        $cookies = [];
        $this->http($cookies, 'POST', $login, ['email' => $user->email, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('two_factor', true);
        if ($policy === 'ip') {
            SecuritySetting::current()->update(['enforce_admin_ip' => true, 'admin_ip_allowlist' => ['10.0.0.0/8']]);
        } elseif ($policy === 'inactive') {
            $user->update(['is_active' => false]);
        } else {
            UserDevice::where('user_id', $user->id)->sole()->update(['status' => $policy]);
        }

        $response = $this->http($cookies, 'POST', $challenge, ['recovery_code' => 'login-admission-recovery'])->assertForbidden();
        if (in_array($policy, ['blocked', 'pending'], true)) {
            $response->assertJsonPath('device_'.$policy, true);
        } else {
            $response->assertJsonPath('code', $policy === 'ip' ? 'ip_not_allowed' : 'account_disabled');
        }
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
        $this->assertContains('login-admission-recovery', $user->fresh()->recoveryCodes());
    }

    public static function challengeAdmissionCases(): array
    {
        $cases = [];
        foreach (['native' => ['/login', '/two-factor-challenge'], 'custom' => ['/api/login', '/api/login/two-factor']] as $kind => [$login, $challenge]) {
            foreach (['ip', 'inactive', 'blocked', 'pending'] as $policy) {
                $cases[$kind.' '.$policy] = [$login, $challenge, $policy];
            }
        }

        return $cases;
    }

    #[DataProvider('accountAdmissionCases')]
    public function test_native_account_mutations_cannot_escape_admission_policy(string $policy): void
    {
        $user = $this->member($policy === 'ip' ? ['role' => 'admin'] : []);
        $name = $user->name;
        $cookies = [];
        $this->http($cookies, 'POST', '/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertOk();
        if ($policy === 'ip') {
            SecuritySetting::current()->update(['enforce_admin_ip' => true, 'admin_ip_allowlist' => ['10.0.0.0/8']]);
        } elseif ($policy === 'inactive') {
            $user->update(['is_active' => false]);
        } else {
            UserDevice::where('user_id', $user->id)->sole()->update(['status' => $policy]);
        }

        $this->http($cookies, 'PUT', '/user/profile-information', ['name' => 'Forbidden mutation', 'email' => $user->email])
            ->assertForbidden();
        $this->http($cookies, 'PUT', '/user/password', [
            'current_password' => 'Secret123!', 'password' => 'ChangedSecret123!', 'password_confirmation' => 'ChangedSecret123!',
        ])->assertForbidden();
        $this->assertSame($name, $user->fresh()->name);
        $this->assertTrue(Hash::check('Secret123!', $user->fresh()->password));
        $this->http($cookies, 'POST', '/logout')->assertNoContent();
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
    }

    public static function accountAdmissionCases(): array
    {
        return ['inactive' => ['inactive'], 'network' => ['ip'], 'blocked' => ['blocked'], 'pending' => ['pending']];
    }

    #[DataProvider('accountAdmissionCases')]
    public function test_google_login_checks_admission_before_authenticating(string $policy): void
    {
        $user = $this->member(['google_id' => 'google-subject', ...($policy === 'ip' ? ['role' => 'admin'] : [])]);
        $cookies = [];
        $this->http($cookies, 'POST', '/api/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertOk();
        $this->http($cookies, 'POST', '/api/logout')->assertOk();
        if ($policy === 'ip') {
            SecuritySetting::current()->update(['enforce_admin_ip' => true, 'admin_ip_allowlist' => ['10.0.0.0/8']]);
        } elseif ($policy === 'inactive') {
            $user->update(['is_active' => false]);
        } else {
            UserDevice::where('user_id', $user->id)->sole()->update(['status' => $policy]);
        }
        $this->google($user->email);
        $this->http($cookies, 'GET', '/auth/google/callback')->assertRedirect('/login?error=account_restricted');
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
    }

    #[DataProvider('providerVerificationClaims')]
    public function test_new_google_signup_trusts_only_the_provider_verified_email_claim(mixed $claim, bool $verified): void
    {
        $this->google('new-google@example.com', $claim);
        $cookies = [];
        $this->http($cookies, 'GET', '/auth/google?intent=register')->assertRedirect();
        $this->http($cookies, 'GET', '/auth/google/callback?email_verified=1')->assertRedirect('/dashboard');
        $user = User::where('email', 'new-google@example.com')->sole();
        $this->assertSame($verified, $user->email_verified_at !== null);
        if ($verified) {
            Mail::assertNotSent(EmailOtp::class);
            $this->http($cookies, 'GET', '/api/dashboard')->assertOk();
        } else {
            Mail::assertSent(EmailOtp::class, fn ($mail) => $mail->hasTo($user->email));
            $this->http($cookies, 'GET', '/api/dashboard')->assertForbidden()->assertJsonPath('email_unverified', true);
            $code = Mail::sent(EmailOtp::class)->sole()->code;
            $this->http($cookies, 'POST', '/api/u/verify-email', ['code' => $code])->assertOk();
            $this->http($cookies, 'GET', '/api/dashboard')->assertOk();
        }
    }

    public static function providerVerificationClaims(): array
    {
        return ['verified' => [true, true], 'false' => [false, false], 'absent' => [null, false], 'string false' => ['false', false]];
    }

    public function test_native_login_and_two_factor_enrollment_remain_available_to_an_unenrolled_admin(): void
    {
        $user = $this->member(['role' => 'admin']);
        SecuritySetting::current()->update(['require_admin_2fa' => true]);
        $cookies = [];
        $this->http($cookies, 'POST', '/login', ['email' => $user->email, 'password' => 'Secret123!'])->assertOk();
        $this->http($cookies, 'POST', '/user/confirm-password', ['password' => 'Secret123!'])->assertCreated();
        $this->http($cookies, 'POST', '/user/two-factor-authentication')->assertOk();
        $this->assertNotNull($user->fresh()->two_factor_secret);
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_native_challenge_still_authenticates_a_policy_compliant_admin(): void
    {
        $admin = $this->factorUser(['role' => 'admin']);
        SecuritySetting::current()->update(['require_admin_2fa' => true]);
        $cookies = [];
        $this->http($cookies, 'POST', '/login', ['email' => $admin->email, 'password' => 'Secret123!'])
            ->assertOk()->assertJsonPath('two_factor', true);
        $this->http($cookies, 'POST', '/two-factor-challenge', ['recovery_code' => 'login-admission-recovery'])->assertNoContent();
        $this->http($cookies, 'GET', '/api/security/settings')->assertOk();
    }

    #[DataProvider('passwordDeviceAdmissionCases')]
    public function test_password_login_does_not_issue_a_session_for_a_denied_browser(string $path, string $status): void
    {
        $member = $this->member();
        $cookies = [];
        $this->http($cookies, 'POST', '/api/login', ['email' => $member->email, 'password' => 'Secret123!'])->assertOk();
        $this->http($cookies, 'POST', '/api/logout')->assertOk();
        UserDevice::where('user_id', $member->id)->sole()->update(['status' => $status]);

        $this->http($cookies, 'POST', $path, ['email' => $member->email, 'password' => 'Secret123!'])
            ->assertForbidden()->assertJsonPath('device_'.$status, true);
        $this->http($cookies, 'GET', '/api/user')->assertUnauthorized();
        $this->assertSame(1, UserDevice::where('user_id', $member->id)->count());
    }

    public static function passwordDeviceAdmissionCases(): array
    {
        return [
            'native blocked' => ['/login', 'blocked'], 'native pending' => ['/login', 'pending'],
            'custom blocked' => ['/api/login', 'blocked'], 'custom pending' => ['/api/login', 'pending'],
        ];
    }

    #[DataProvider('factorAdmissionPaths')]
    public function test_unfinished_logins_cannot_fill_device_slots_but_completed_factors_enforce_the_limit(string $kind, string $login, string $challenge): void
    {
        $user = $this->factorUser([
            'google_id' => 'google-subject',
            'two_factor_recovery_codes' => encrypt(json_encode(['factor-one', 'factor-two', 'factor-three'])),
        ]);
        if ($kind === 'google') {
            $this->google($user->email);
        }
        $clients = [1 => [], 2 => [], 3 => []];
        foreach ($clients as $index => &$cookies) {
            $headers = ['HTTP_USER_AGENT' => 'Factor browser '.$index];
            if ($kind === 'google') {
                $this->http($cookies, 'GET', $login, [], $headers)->assertRedirect('/login?two_factor=1');
            } else {
                $this->http($cookies, 'POST', $login, ['email' => $user->email, 'password' => 'Secret123!'], $headers)
                    ->assertOk()->assertJsonPath('two_factor', true);
            }
            $this->assertSame(0, UserDevice::where('user_id', $user->id)->count());
        }
        unset($cookies);

        $this->http($clients[1], 'POST', $challenge, ['recovery_code' => 'incorrect'], ['HTTP_USER_AGENT' => 'Factor browser 1'])
            ->assertUnprocessable();
        $this->assertSame(0, UserDevice::where('user_id', $user->id)->count());
        foreach ([1 => 'factor-one', 2 => 'factor-two'] as $index => $code) {
            $this->http($clients[$index], 'POST', $challenge, ['recovery_code' => $code], ['HTTP_USER_AGENT' => 'Factor browser '.$index])
                ->assertStatus($kind === 'native' ? 204 : 200);
            $this->http($clients[$index], 'GET', '/api/user', [], ['HTTP_USER_AGENT' => 'Factor browser '.$index])
                ->assertOk()->assertJsonPath('id', $user->id);
        }

        $this->http($clients[3], 'POST', $challenge, ['recovery_code' => 'factor-three'], ['HTTP_USER_AGENT' => 'Factor browser 3'])
            ->assertForbidden()->assertJsonPath('device_pending', true);
        $this->assertContains('factor-three', $user->fresh()->recoveryCodes());
        $this->http($clients[3], 'GET', '/api/user', [], ['HTTP_USER_AGENT' => 'Factor browser 3'])->assertUnauthorized();
        $this->assertSame(2, UserDevice::where('user_id', $user->id)->where('status', 'active')->count());
        $this->assertSame(1, UserDevice::where('user_id', $user->id)->where('status', 'pending')->count());
        UserDevice::where('user_id', $user->id)->where('status', 'pending')->sole()->update(['status' => 'active']);
        $headers = ['HTTP_USER_AGENT' => 'Factor browser 3'];
        if ($kind === 'google') {
            $this->http($clients[3], 'GET', $login, [], $headers)->assertRedirect('/login?two_factor=1');
        } else {
            $this->http($clients[3], 'POST', $login, ['email' => $user->email, 'password' => 'Secret123!'], $headers)
                ->assertOk()->assertJsonPath('two_factor', true);
        }
        $this->http($clients[3], 'POST', $challenge, ['recovery_code' => 'factor-three'], $headers)
            ->assertStatus($kind === 'native' ? 204 : 200);
        $this->http($clients[3], 'GET', '/api/user', [], $headers)->assertOk()->assertJsonPath('id', $user->id);
        $this->assertNotContains('factor-three', $user->fresh()->recoveryCodes());
    }

    #[DataProvider('factorAdmissionPaths')]
    public function test_a_first_factor_cannot_record_activity_for_an_existing_device(string $kind, string $login, string $challenge): void
    {
        $user = $this->factorUser(['google_id' => 'google-subject']);
        $device = $this->knownDevice($user);
        $lastSeen = $device->fresh()->last_active_at;
        $cookies = [];
        if ($kind === 'google') {
            $this->google($user->email);
            $this->http($cookies, 'GET', $login)->assertRedirect('/login?two_factor=1');
        } else {
            $this->http($cookies, 'POST', $login, ['email' => $user->email, 'password' => 'Secret123!'])
                ->assertOk()->assertJsonPath('two_factor', true);
        }
        $this->assertEquals($lastSeen, $device->fresh()->last_active_at);
        $this->assertSame('192.0.2.10', $device->fresh()->ip_address);

        $this->http($cookies, 'POST', $challenge, ['recovery_code' => 'login-admission-recovery'])
            ->assertStatus($kind === 'native' ? 204 : 200);
        $this->assertTrue($device->fresh()->last_active_at->gt($lastSeen));
        $this->assertSame('127.0.0.1', $device->fresh()->ip_address);
    }

    public static function factorAdmissionPaths(): array
    {
        return [
            'custom' => ['custom', '/api/login', '/api/login/two-factor'],
            'native' => ['native', '/login', '/two-factor-challenge'],
            'google' => ['google', '/auth/google/callback', '/api/login/two-factor'],
        ];
    }

    public function test_custom_challenge_returns_the_rotated_csrf_token_for_clients_without_fetch_metadata(): void
    {
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        Route::middleware('web')->get('/api/health', fn (Request $request) => response()->json([
            'csrf_token' => $request->session()->token(),
        ]));
        $user = $this->factorUser();
        $cookies = [];
        $oldToken = $this->http($cookies, 'GET', '/api/health')->assertOk()->json('csrf_token');
        $first = $this->http($cookies, 'POST', '/api/login', [
            'email' => $user->email, 'password' => 'Secret123!',
        ], ['HTTP_X_CSRF_TOKEN' => $oldToken])->assertOk()->assertJsonPath('two_factor', true);
        $newToken = $first->json('csrf_token');
        $this->assertIsString($newToken);
        $this->assertNotSame($oldToken, $newToken);

        // Prove token validation is active rather than Laravel's testing/Fetch Metadata exemption.
        $this->http($cookies, 'POST', '/api/login/two-factor', [
            'recovery_code' => 'login-admission-recovery',
        ], ['HTTP_X_CSRF_TOKEN' => $oldToken])->assertStatus(419);
        $this->http($cookies, 'POST', '/api/login/two-factor', [
            'recovery_code' => 'login-admission-recovery',
        ], ['HTTP_X_CSRF_TOKEN' => $newToken])->assertOk()->assertJsonPath('user.id', $user->id);
        $this->http($cookies, 'GET', '/api/user')->assertOk()->assertJsonPath('id', $user->id);
    }

    private function knownDevice(User $user): UserDevice
    {
        $request = Request::create('http://localhost', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'LoginAdmissionSecurityTest']);

        return UserDevice::create([
            'user_id' => $user->id, 'device_hash' => UserDevice::generateFingerprint($user->id, $request),
            'device_name' => 'Known browser', 'device_type' => 'desktop', 'user_agent' => $request->userAgent(),
            'status' => 'active', 'ip_address' => '192.0.2.10', 'last_active_at' => now()->subDay(),
        ]);
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'member', 'is_active' => true, 'password' => 'Secret123!', ...$attributes]);
    }

    private function factorUser(array $attributes = []): User
    {
        return $this->member([
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => encrypt(json_encode(['login-admission-recovery'])), ...$attributes,
        ]);
    }

    private function google(string $email, mixed $claim = true): void
    {
        $raw = ['email' => $email];
        if ($claim !== null) {
            $raw['email_verified'] = $claim;
        }
        $user = (new GoogleUser)->setRaw($raw)->map(['id' => 'google-subject', 'name' => 'Google Member', 'email' => $email]);
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($user);
        $provider->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/mock'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    /** Independent clients send real encrypted cookies through the application middleware. */
    private function http(array &$cookies, string $method, string $path, array $data = [], array $server = []): TestResponse
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
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'LoginAdmissionSecurityTest',
            ...$server,
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
