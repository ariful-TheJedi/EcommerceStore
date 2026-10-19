<?php

namespace Tests\Feature\Auth;

use App\Auth\Notifications\EmailVerificationNotification;
use App\Auth\Notifications\PasswordRecoveryNotification;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $sessionCookie = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'auth.mailer' => 'array', 'auth.customer_enabled' => true, 'auth.email_enabled' => true]);
    }

    #[DataProvider('accountRoles')]
    public function test_login_and_me_return_safe_identity_for_the_correct_guard(string $role, string $accountType): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->api('POST', $accountType.'/login', ['email' => strtoupper($user->email), 'password' => 'password'])
            ->assertOk()->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.principal_type', $accountType)->assertJsonMissingPath('data.user.password');
        $this->api('GET', $accountType.'/me')->assertOk()->assertJsonPath('data.user.role', $role)
            ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.token');
        $this->api('GET', ($accountType === 'staff' ? 'customer' : 'staff').'/me')->assertUnauthorized();
    }

    public static function accountRoles(): array
    {
        return ['customer' => ['customer', 'customer'], 'staff' => ['staff', 'staff'], 'admin' => ['admin', 'staff']];
    }

    #[DataProvider('rejectedCredentials')]
    public function test_login_returns_generic_401_for_rejected_credentials(string $role, string $accountType, bool $active, string $password, bool $exists): void
    {
        if ($exists) {
            User::factory()->create(['email' => 'login@example.test', 'role' => $role, 'is_active' => $active]);
        }

        $this->api('POST', $accountType.'/login', ['email' => 'login@example.test', 'password' => $password])
            ->assertUnauthorized()->assertJsonPath('message', 'The provided credentials are invalid.');
        $this->api('GET', $accountType.'/me')->assertUnauthorized();
    }

    public static function rejectedCredentials(): array
    {
        return [
            'wrong password' => ['customer', 'customer', true, 'incorrect', true],
            'unknown account' => ['customer', 'customer', true, 'password', false],
            'disabled customer' => ['customer', 'customer', false, 'password', true],
            'disabled staff' => ['staff', 'staff', false, 'password', true],
            'customer via staff' => ['customer', 'staff', true, 'password', true],
            'staff via customer' => ['staff', 'customer', true, 'password', true],
            'admin via customer' => ['admin', 'customer', true, 'password', true],
        ];
    }

    public function test_login_rejects_missing_credentials_and_unexpected_fields(): void
    {
        $this->api('POST', 'customer/login')->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->api('POST', 'customer/login', ['email' => 'a@example.test', 'password' => 'password', 'remember' => true])
            ->assertUnprocessable()->assertJsonValidationErrors('remember');
    }

    public function test_login_limits_repeated_attempts_with_retry_after_header(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->api('POST', 'customer/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnauthorized();
        }

        $this->api('POST', 'customer/login', ['email' => 'missing@example.test', 'password' => 'wrong'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    #[DataProvider('protectedRoutes')]
    public function test_protected_routes_return_401_without_a_session(string $method, string $path): void
    {
        $this->api($method, $path)->assertUnauthorized();
    }

    public static function protectedRoutes(): array
    {
        $cases = [];
        foreach (['customer', 'staff'] as $type) {
            foreach (['me', 'logout', 'logout-all', 'password', 'email', 'email/verify', 'email/resend'] as $path) {
                $cases[$type.'/'.$path] = [$path === 'me' ? 'GET' : 'POST', $type.'/'.$path];
            }
        }

        return $cases;
    }

    public function test_disabling_an_account_revokes_its_existing_database_sessions(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
        $user->is_active = false;
        $user->save();

        $this->api('GET', 'customer/me')->assertUnauthorized();

        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
    }

    public function test_logout_invalidates_current_device_and_preserves_another_device(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $firstCookie = $this->sessionCookie;
        $this->sessionCookie = null;
        $this->login($user);

        $this->api('POST', 'customer/logout')->assertOk();
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->sessionCookie = $firstCookie;
        $this->api('GET', 'customer/me')->assertOk();
    }

    public function test_logout_all_invalidates_every_device_but_preserves_other_accounts(): void
    {
        $other = User::factory()->create();
        $this->login($other);
        $otherCookie = $this->sessionCookie;
        $user = User::factory()->create();
        $this->sessionCookie = null;
        $this->login($user);
        $firstCookie = $this->sessionCookie;
        $this->sessionCookie = null;
        $this->login($user);

        $this->api('POST', 'customer/logout-all')->assertOk();
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->sessionCookie = $firstCookie;
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->sessionCookie = $otherCookie;
        $this->api('GET', 'customer/me')->assertOk()->assertJsonPath('data.user.id', $other->id);
    }

    public function test_sessions_expire_after_idle_timeout(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->login($user);
        $this->travel(121)->minutes();

        $this->api('GET', 'customer/me')->assertUnauthorized();
    }

    public function test_sessions_expire_after_absolute_lifetime_even_if_recently_active(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $this->login($user);
        $this->travel(721)->minutes();
        DB::table('sessions')->where('user_id', $user->id)->update(['last_activity' => now()->timestamp]);

        $this->api('GET', 'customer/me')->assertUnauthorized();
    }

    public function test_password_change_requires_current_password_and_ownership(): void
    {
        $user = User::factory()->create();
        $this->login($user);

        $this->api('POST', 'customer/password', [
            'current_password' => 'incorrect', 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
        ])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->api('POST', 'customer/password', [
            'current_password' => 'password', 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123', 'user_id' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_password_change_revokes_all_sessions_and_reset_tokens(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $oldCookie = $this->sessionCookie;
        $this->sessionCookie = null;
        $this->login($user);
        Password::broker('users')->createToken($user);

        $this->api('POST', 'customer/password', [
            'current_password' => 'password', 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewPassword@123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->sessionCookie = $oldCookie;
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->api('POST', 'customer/login', ['email' => $user->email, 'password' => 'NewPassword@123'])->assertOk();
    }

    public function test_password_fingerprint_rejects_a_session_restored_after_a_password_change(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $oldSession = (array) DB::table('sessions')->where('user_id', $user->id)->first();
        $user->password = 'ChangedPassword@123';
        $user->save();
        DB::table('sessions')->where('id', $oldSession['id'])->update($oldSession);

        $this->api('GET', 'customer/me')->assertUnauthorized();
    }

    public function test_forgot_password_sends_a_hashed_token_only_to_an_eligible_verified_account(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->api('POST', 'customer/forgot-password', ['email' => $user->email])->assertOk();

        Notification::assertSentTo($user, PasswordRecoveryNotification::class, function ($notification) use ($user): bool {
            return $notification->accountType === 'customer'
                && Password::broker('users')->tokenExists($user, $notification->token)
                && $notification->token !== DB::table('password_reset_tokens')->where('email', $user->email)->value('token');
        });
    }

    public function test_forgot_password_returns_the_same_response_for_unknown_disabled_unverified_and_wrong_role_accounts(): void
    {
        Notification::fake();
        $disabled = User::factory()->create(['is_active' => false]);
        $unverified = User::factory()->unverified()->create();
        $staff = User::factory()->create(['role' => 'staff']);

        foreach ([$disabled->email, $unverified->email, $staff->email, 'missing@example.test'] as $email) {
            $this->api('POST', 'customer/forgot-password', ['email' => $email])->assertOk()
                ->assertExactJson(['message' => 'If an eligible account exists, password reset instructions will be sent.', 'data' => []]);
        }

        Notification::assertNothingSent();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_password_reset_changes_password_consumes_token_and_revokes_sessions(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $cookie = $this->sessionCookie;
        $token = Password::broker('users')->createToken($user);

        $this->api('POST', 'customer/reset-password', $this->resetPayload($user, $token))->assertOk();

        $this->assertTrue(Hash::check('NewPassword@123', $user->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->sessionCookie = $cookie;
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->api('POST', 'customer/reset-password', $this->resetPayload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    public function test_reset_rejects_expired_tokens_and_tokens_for_another_account(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $other = User::factory()->create();
        $token = Password::broker('users')->createToken($user);

        $this->api('POST', 'customer/reset-password', $this->resetPayload($other, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->travel(61)->minutes();
        $this->api('POST', 'customer/reset-password', $this->resetPayload($user, $token))
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_reset_rejects_a_valid_staff_token_through_customer_flow_and_accepts_staff_flow(): void
    {
        $user = User::factory()->create(['role' => 'staff']);
        $token = Password::broker('users')->createToken($user);

        $this->api('POST', 'customer/reset-password', $this->resetPayload($user, $token))->assertUnprocessable();
        $this->api('POST', 'staff/reset-password', $this->resetPayload($user, $token))->assertOk();
        $this->assertTrue(Hash::check('NewPassword@123', $user->fresh()->password));
    }

    public function test_verification_is_owned_expiring_single_use_and_resend_invalidates_previous_token(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $this->login($user);
        $this->api('POST', 'customer/email/resend')->assertOk();
        $firstToken = Notification::sent($user, EmailVerificationNotification::class)->last()->token;
        $this->api('POST', 'customer/email/resend')->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->api('POST', 'customer/email/resend')->assertOk();
        $token = Notification::sent($user, EmailVerificationNotification::class)->last()->token;

        $this->api('POST', 'customer/email/verify', ['token' => $firstToken])->assertUnprocessable();
        $other = User::factory()->unverified()->create();
        $cookie = $this->sessionCookie;
        $this->sessionCookie = null;
        $this->login($other);
        $this->api('POST', 'customer/email/verify', ['token' => $token])->assertUnprocessable();
        $this->assertNull($other->fresh()->email_verified_at);
        $this->sessionCookie = $cookie;
        $this->api('POST', 'customer/email/verify', ['token' => $token])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseMissing('email_verification_tokens', ['user_id' => $user->id]);
        $this->api('POST', 'customer/email/verify', ['token' => $token])->assertUnprocessable();
    }

    public function test_verification_rejects_expired_token(): void
    {
        Notification::fake();
        $this->freezeTime();
        $user = User::factory()->unverified()->create();
        $this->login($user);
        $this->api('POST', 'customer/email/resend')->assertOk();
        $token = Notification::sent($user, EmailVerificationNotification::class)->last()->token;
        $this->travel(61)->minutes();

        $this->api('POST', 'customer/email/verify', ['token' => $token])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_auth_mail_refuses_log_transport_and_reports_delivery_failure_without_exposing_tokens(): void
    {
        config(['auth.mailer' => 'log']);
        $user = User::factory()->unverified()->create();
        $this->login($user);

        $this->api('POST', 'customer/email/resend')->assertStatus(503)
            ->assertJsonPath('message', 'Email delivery is unavailable. Please try again later.');
    }

    public function test_csrf_is_enforced_on_auth_posts_and_login_rotates_the_token(): void
    {
        $user = User::factory()->create();
        $this->app->instance('env', 'local');
        $token = $this->api('GET', 'csrf-token')->assertOk()->json('data.csrf_token');

        $this->api('POST', 'customer/login', ['email' => $user->email, 'password' => 'password'])->assertStatus(419);
        $response = $this->api('POST', 'customer/login', ['email' => $user->email, 'password' => 'password'], ['HTTP_X_CSRF_TOKEN' => $token]);
        $response->assertOk();
        $this->assertNotSame($token, $response->json('data.csrf_token'));
        $this->api('POST', 'customer/logout', [], ['HTTP_X_CSRF_TOKEN' => $token])->assertStatus(419);
        $this->api('POST', 'customer/logout', [], ['HTTP_X_CSRF_TOKEN' => $response->json('data.csrf_token')])->assertOk();
    }

    public function test_email_change_requires_current_password_invalidates_old_tokens_and_requires_verifying_new_email(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $oldEmail = $user->email;
        Password::broker('users')->createToken($user);
        $this->login($user);
        $oldCookie = $this->sessionCookie;

        $this->api('POST', 'customer/email', ['email' => 'changed@example.test', 'current_password' => 'wrong'])
            ->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertSame($oldEmail, $user->fresh()->email);
        $this->api('POST', 'customer/email', ['email' => 'Changed@Example.Test', 'current_password' => 'password'])
            ->assertOk()->assertJsonPath('data.verification_sent', true);
        $this->assertNull($user->fresh()->email_verified_at);
        $this->assertSame('changed@example.test', $user->fresh()->email);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $oldEmail]);
        $this->sessionCookie = $oldCookie;
        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->login($user->fresh());
        $token = Notification::sent($user, EmailVerificationNotification::class)->last()->token;
        $this->api('POST', 'customer/email/verify', ['token' => $token])->assertOk();
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    public function test_email_changes_invalidate_previously_issued_verification_tokens(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();
        $this->login($user);
        $this->api('POST', 'customer/email/resend')->assertOk();
        $oldToken = Notification::sent($user, EmailVerificationNotification::class)->last()->token;

        $this->api('POST', 'customer/email', ['email' => 'changed@example.test', 'current_password' => 'password'])->assertOk();
        $this->login($user->fresh());
        $this->api('POST', 'customer/email/verify', ['token' => $oldToken])->assertUnprocessable();
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_a_disabled_then_reactivated_account_cannot_reuse_old_sessions_or_reset_tokens(): void
    {
        $user = User::factory()->create();
        $this->login($user);
        $token = Password::broker('users')->createToken($user);
        $user->is_active = false;
        $user->save();
        $user->is_active = true;
        $user->save();

        $this->api('GET', 'customer/me')->assertUnauthorized();
        $this->api('POST', 'customer/reset-password', $this->resetPayload($user, $token))->assertUnprocessable();
    }

    private function resetPayload(User $user, string $token): array
    {
        return ['email' => $user->email, 'token' => $token, 'password' => 'NewPassword@123', 'password_confirmation' => 'NewPassword@123'];
    }

    private function login(User $user, string $password = 'password'): void
    {
        $type = $user->role === 'customer' ? 'customer' : 'staff';
        $this->api('POST', $type.'/login', ['email' => $user->email, 'password' => $password])->assertOk();
    }

    private function api(string $method, string $path, array $data = [], array $server = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $cookieName = config('session.cookie');
        $cookies = $this->sessionCookie === null ? [] : [$cookieName => $this->sessionCookie];
        $response = $this->call($method, (str_starts_with($path, '/') ? $path : '/api/auth/'.$path), [], $cookies, [], array_replace([
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
        ], $server), json_encode($data));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                $this->sessionCookie = $cookie->getValue();
            }
        }

        return $response;
    }
}
