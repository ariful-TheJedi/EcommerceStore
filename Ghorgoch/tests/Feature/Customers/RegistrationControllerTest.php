<?php

namespace Tests\Feature\Customers;

use App\Auth\Notifications\EmailVerificationNotification;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class RegistrationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    private ?string $sessionCookie = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['session.driver' => 'database', 'auth.mailer' => 'array', 'auth.customer_enabled' => true, 'auth.email_enabled' => true, 'customers.registration_enabled' => true]);
    }

    public function test_registration_creates_only_an_unverified_customer_and_sends_a_hashed_verification_token(): void
    {
        Notification::fake();

        $response = $this->api('POST', '/api/customers/register', $this->registration(['email' => ' New.Customer@Example.Test ']));

        $response->assertCreated()->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email_verified', false)->assertJsonPath('data.verification_sent', true);
        $user = User::where('email', 'new.customer@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('StrongPassword@123', $user->password));
        $this->assertDatabaseHas('customers', ['user_id' => $user->id]);
        Notification::assertSentTo($user, EmailVerificationNotification::class, function ($notification) use ($user): bool {
            return hash('sha256', $notification->token.'|'.$user->email) === $user->emailVerificationToken->token_hash
                && $notification->token !== $user->emailVerificationToken->token_hash;
        });
        $this->api('GET', 'customer/me')->assertUnauthorized();
    }

    #[DataProvider('invalidRegistration')]
    public function test_registration_returns_422_without_creating_an_account_for_invalid_fields(array $overrides, string $field): void
    {
        $this->api('POST', '/api/customers/register', $this->registration($overrides))
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public static function invalidRegistration(): array
    {
        return [
            'staff role' => [['role' => 'admin'], 'role'],
            'permissions' => [['permissions' => ['manage_staff']], 'permissions'],
            'verified flag' => [['email_verified_at' => '2026-10-17'], 'email_verified_at'],
            'inactive flag' => [['is_active' => false], 'is_active'],
            'account id' => [['user_id' => 1], 'user_id'],
            'missing name' => [['name' => ''], 'name'],
            'long name' => [['name' => str_repeat('a', 151)], 'name'],
            'malformed email' => [['email' => 'not-an-email'], 'email'],
            'email array' => [['email' => ['admin@example.test']], 'email'],
            'bad phone' => [['phone' => '01700000000'], 'phone'],
            'short password' => [['password' => 'Short@123', 'password_confirmation' => 'Short@123'], 'password'],
            'weak password' => [['password' => 'abcdefghijklmn', 'password_confirmation' => 'abcdefghijklmn'], 'password'],
            'wrong confirmation' => [['password_confirmation' => 'WrongPassword@123'], 'password'],
            'bcrypt byte limit' => [['password' => str_repeat('é', 35).'Aa1!', 'password_confirmation' => str_repeat('é', 35).'Aa1!'], 'password'],
        ];
    }

    public function test_registration_rejects_duplicate_email_case_insensitively_and_duplicate_phone(): void
    {
        User::factory()->create(['email' => 'Existing@Example.Test', 'phone' => '+8801712345678']);

        $this->api('POST', '/api/customers/register', $this->registration(['email' => 'existing@example.test']))
            ->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->api('POST', '/api/customers/register', $this->registration(['phone' => '+8801712345678']))
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_rolls_back_the_user_if_customer_profile_creation_fails(): void
    {
        Customer::creating(function (): void {
            throw new RuntimeException('Private database failure');
        });

        $this->api('POST', '/api/customers/register', $this->registration())
            ->assertStatus(500)->assertExactJson(['message' => 'Unable to process this request.', 'data' => []]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_registration_reports_unavailable_delivery_without_exposing_tokens(): void
    {
        config(['auth.mailer' => 'log']);

        $this->api('POST', '/api/customers/register', $this->registration())->assertCreated()
            ->assertJsonPath('data.verification_sent', false)->assertJsonMissingPath('data.token');
    }

    private function registration(array $overrides = []): array
    {
        return array_replace([
            'name' => 'New Customer',
            'email' => 'new@example.test',
            'password' => 'StrongPassword@123',
            'password_confirmation' => 'StrongPassword@123',
        ], $overrides);
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
