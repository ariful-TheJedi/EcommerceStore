<?php

namespace Tests\Feature\Customers;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class RegistrationCsrfTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['session.driver' => 'database', 'session.lottery' => [0, 100],
            'auth.customer_enabled' => true, 'customers.registration_enabled' => true, 'auth.email_enabled' => false]);
        $this->app->instance('env', 'local');
        $this->assertFalse($this->app->runningUnitTests());
    }

    #[TestWith(['missing', false])]
    #[TestWith(['wrong', false])]
    #[TestWith(['foreign', false])]
    #[TestWith(['missing', true])]
    #[TestWith(['wrong', true])]
    #[TestWith(['foreign', true])]
    public function test_registration_rejects_invalid_csrf_with_419(string $kind, bool $sameOrigin): void
    {
        Notification::fake();
        $cookies = [];
        $this->request($cookies, 'GET', '/api/auth/csrf-token')->assertOk();
        $token = $kind === 'missing' ? null : 'invalid-token';
        if ($kind === 'foreign') {
            $foreignCookies = [];
            $token = $this->request($foreignCookies, 'GET', '/api/auth/csrf-token')->assertOk()->json('data.csrf_token');
        }
        $headers = $sameOrigin ? ['HTTP_SEC_FETCH_SITE' => 'same-origin'] : [];

        $this->request($cookies, 'POST', '/api/customers/register', token: $token, headers: $headers)
            ->assertStatus(419)->assertHeader('Content-Type', 'application/json')
            ->assertExactJson(['message' => 'CSRF token mismatch. Fetch a new CSRF token.', 'data' => []]);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        Notification::assertNothingSent();
    }

    public function test_matching_csrf_and_cookie_allow_registration_and_respect_the_feature_flag(): void
    {
        Notification::fake();
        $cookies = [];
        $token = $this->request($cookies, 'GET', '/api/auth/csrf-token')->assertOk()->json('data.csrf_token');
        config(['customers.registration_enabled' => false]);
        $this->request($cookies, 'POST', '/api/customers/register', token: $token)->assertForbidden();
        $this->assertDatabaseCount('users', 0);
        config(['customers.registration_enabled' => true]);
        $payload = ['name' => 'CSRF Customer', 'email' => 'csrf-customer@example.test',
            'password' => 'StrongPassword@123', 'password_confirmation' => 'StrongPassword@123'];

        $this->request($cookies, 'POST', '/api/customers/register', $payload, $token)->assertCreated();

        $this->assertDatabaseHas('users', ['email' => 'csrf-customer@example.test', 'role' => 'customer']);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        Notification::assertNothingSent();
    }

    private function request(array &$cookies, string $method, string $path, array $data = [], ?string $token = null, array $headers = []): TestResponse
    {
        Auth::forgetGuards();
        $this->app->forgetInstance('auth.driver');
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
        $server = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', ...$headers];
        if ($token !== null) {
            $server['HTTP_X_CSRF_TOKEN'] = $token;
        }
        $response = $this->call($method, $path, [], $cookies, [], $server, json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }
}
