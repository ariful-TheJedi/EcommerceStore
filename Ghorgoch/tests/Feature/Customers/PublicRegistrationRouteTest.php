<?php

namespace Tests\Feature\Customers;

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PublicRegistrationRouteTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        config(['auth.customer_enabled' => true, 'customers.registration_enabled' => true, 'auth.email_enabled' => false]);
    }

    public function test_registration_exists_only_under_customers_without_auth_alias_or_redirect(): void
    {
        $this->assertTrue(Route::has('customers.register'));
        $this->assertFalse(Route::has('auth.customer.register'));
        $this->assertSame('api/customers/register', Route::getRoutes()->getByName('customers.register')->uri());
        $this->postJson('/api/auth/customer/register')->assertNotFound()->assertHeaderMissing('Location');
        $this->postJson('/api/auth/staff/register')->assertNotFound();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customers_url_creates_an_unverified_customer_without_login_or_mail(): void
    {
        Notification::fake();
        $payload = ['name' => 'Public Customer', 'email' => ' Customer@Example.Test ',
            'password' => 'StrongPassword@123', 'password_confirmation' => 'StrongPassword@123'];

        $this->postJson('/api/customers/register', $payload)
            ->assertCreated()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('message', 'Customer registered. Log in to your account.')
            ->assertJsonPath('data.user.email', 'customer@example.test')
            ->assertJsonPath('data.user.role', 'customer')
            ->assertJsonPath('data.user.email_verified', false)
            ->assertJsonPath('data.verification_sent', false)
            ->assertJsonMissingPath('data.user.password');

        $user = User::where('email', 'customer@example.test')->sole();
        $this->assertTrue(Hash::check('StrongPassword@123', $user->password));
        $this->assertTrue($user->is_active);
        $this->assertNull($user->email_verified_at);
        $this->assertDatabaseHas('customers', ['user_id' => $user->id]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        $this->getJson('/api/auth/customer/me')->assertUnauthorized();
        Notification::assertNothingSent();
    }

    public function test_customers_url_respects_the_registration_feature_flag(): void
    {
        config(['customers.registration_enabled' => false]);

        $this->postJson('/api/customers/register')->assertForbidden();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
    }

    public function test_customers_registration_is_limited_to_five_attempts_per_minute(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/customers/register')->assertUnprocessable();
        }

        $this->postJson('/api/customers/register')->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
    }
}
