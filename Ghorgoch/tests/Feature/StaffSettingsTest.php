<?php

namespace Tests\Feature;

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class StaffSettingsTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param array<string, string> $cookies
     * @param  array<string, mixed>  $data
     */
    private function browser(array &$cookies, string $method, string $path, array $data = [], bool $csrf = true): TestResponse
    {
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($method !== 'GET' && $csrf) {
            $headers['HTTP_X_CSRF_TOKEN'] = $this->browser($cookies, 'GET', '/api/auth/csrf-token')->assertOk()->json('data.csrf_token');
        }
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $response = $this->call($method, $path, [], $cookies, [], $headers, json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    /** @param array<string, string> $cookies */
    private function login(User $user, array &$cookies): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $this->browser($cookies, 'POST', '/api/auth/staff/login', ['email' => $user->email, 'password' => 'OriginalPassword123!'])->assertOk();
    }

    public function test_staff_can_update_name_without_staff_management_permission(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => [], 'password' => 'OriginalPassword123!']);
        $cookies = [];
        $this->login($user, $cookies);

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['name' => 'Updated Name'])->assertOk()->assertJsonPath('data.user.name', 'Updated Name');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'Updated Name']);
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.name', 'Updated Name');
    }

    public function test_email_updates_without_verification_mail_and_preserves_current_session_only(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!', 'email_verified_at' => now()]);
        $cookies = [];
        $other = [];
        $this->login($user, $other);
        $this->login($user, $cookies);
        Notification::fake();

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['email' => ' New.Address@Example.Test ', 'current_password' => 'OriginalPassword123!'])
            ->assertOk()->assertJsonPath('data.user.email', 'new.address@example.test')->assertJsonMissingPath('data.user.password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => 'new.address@example.test', 'email_verified_at' => null]);
        Notification::assertNothingSent();
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.email', 'new.address@example.test');
        $this->browser($other, 'GET', '/api/auth/staff/me')->assertUnauthorized();
    }

    public function test_password_change_hashes_new_password_and_keeps_cookie_session_valid(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        $cookies = [];
        $other = [];
        $this->login($user, $other);
        $this->login($user, $cookies);

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['current_password' => 'OriginalPassword123!', 'password' => 'UpdatedPassword456!', 'password_confirmation' => 'UpdatedPassword456!'])
            ->assertOk()->assertJsonMissingPath('data.user.password');

        $this->assertTrue(Hash::check('UpdatedPassword456!', $user->fresh()->password));
        $this->assertFalse(Hash::check('OriginalPassword123!', $user->fresh()->password));
        $this->assertDatabaseHas('staff_activity', ['staff_id' => $user->id, 'action' => 'password_changed']);
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk();
        $this->browser($other, 'GET', '/api/auth/staff/me')->assertUnauthorized();
    }

    public function test_staff_cannot_change_role_even_with_role_assignment_permission(): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => ['roles.assign', 'staff.assign_self'], 'password' => 'OriginalPassword123!']);
        $cookies = [];
        $this->login($user, $cookies);

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['role' => 'admin', 'current_password' => 'OriginalPassword123!'])->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'staff']);
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk();
    }

    public function test_admin_can_change_own_role_when_another_active_admin_exists(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        User::factory()->create(['role' => 'admin', 'is_active' => true]);
        $cookies = [];
        $this->login($user, $cookies);

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['role' => 'staff', 'current_password' => 'OriginalPassword123!'])->assertOk()->assertJsonPath('data.user.role', 'staff');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'staff']);
        $this->assertDatabaseHas('staff_activity', ['staff_id' => $user->id, 'action' => 'access_changed']);
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.role', 'staff');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['role' => 'admin', 'current_password' => 'OriginalPassword123!'])->assertForbidden();
    }

    public function test_last_active_admin_cannot_demote_self(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $cookies = [];
        $this->login($user, $cookies);

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['role' => 'staff', 'current_password' => 'OriginalPassword123!'])->assertConflict();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'admin']);
        $this->browser($cookies, 'GET', '/api/auth/staff/me')->assertOk();
    }

    public function test_sensitive_changes_require_correct_password_and_valid_new_password(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        $cookies = [];
        $this->login($user, $cookies);
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['email' => 'changed@example.test'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['name' => 'Should not save', 'email' => 'changed@example.test', 'current_password' => 'WrongPassword!'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['password' => 'weak', 'password_confirmation' => 'weak', 'current_password' => 'OriginalPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['password' => 'UpdatedPassword456!', 'password_confirmation' => 'NotMatching!', 'current_password' => 'OriginalPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => $user->name, 'email' => $user->email]);
        $this->assertTrue(Hash::check('OriginalPassword123!', $user->fresh()->password));
    }

    public function test_duplicate_email_and_unexpected_fields_are_rejected(): void
    {
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        User::factory()->create(['email' => 'taken@example.test']);
        $cookies = [];
        $this->login($user, $cookies);
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['email' => 'TAKEN@example.test', 'current_password' => 'OriginalPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['email' => ['invalid'], 'current_password' => 'OriginalPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['permissions' => ['roles.assign'], 'id' => 999])->assertUnprocessable()->assertJsonValidationErrors(['permissions', 'id']);
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['role' => 'customer', 'current_password' => 'OriginalPassword123!'])->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email, 'role' => 'admin']);
    }

    public function test_settings_rejects_unauthenticated_customer_and_csrf_less_requests(): void
    {
        $this->patchJson('/api/users/me/settings', ['name' => 'Unauthorized'])->assertUnauthorized();
        $customer = User::factory()->create(['role' => 'customer']);
        $this->actingAs($customer, 'customer')->patchJson('/api/users/me/settings', ['name' => 'Customer'])->assertUnauthorized();
        Auth::forgetGuards();
        $user = User::factory()->create(['role' => 'admin', 'password' => 'OriginalPassword123!']);
        $cookies = [];
        $this->login($user, $cookies);
        $this->app->instance('env', 'local');

        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['name' => 'CSRF rejected'], false)->assertStatus(419);
        $this->browser($cookies, 'PATCH', '/api/users/me/settings', ['name' => 'CSRF accepted'])->assertOk();

        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'CSRF accepted']);
    }
}
