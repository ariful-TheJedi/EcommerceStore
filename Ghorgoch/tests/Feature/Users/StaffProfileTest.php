<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Dedicated helpers: no dependence on another Pest file's load order.
function staffProfileAccount(string $role = 'staff', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Profile Operator',
        'email' => 'profile-'.uniqid().'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function staffProfileRequest(TestCase $test, array &$cookies, string $method, string $uri, array $payload = [], ?string $csrf = null): TestResponse
{
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($csrf !== null) {
        $headers['HTTP_X_CSRF_TOKEN'] = $csrf;
    }

    $response = $test->call(
        $method,
        $uri,
        $method === 'GET' ? $payload : [],
        $cookies,
        [],
        $headers,
        $method === 'GET' ? null : json_encode($payload, JSON_THROW_ON_ERROR),
    );
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared()) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }

    return $response;
}

function staffProfileLogin(TestCase $test, array &$cookies, User $user): string
{
    $csrfResponse = staffProfileRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();
    $csrf = $csrfResponse->json('data.csrf_token');
    expect($csrf)->toBeString()->not->toBe('');

    $login = staffProfileRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $csrf);
    $login->assertOk()->assertJsonPath('data.user.email', $user->email);
    $newCsrf = $login->json('data.csrf_token');
    expect($newCsrf)->toBeString()->not->toBe('');

    return $newCsrf;
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->profileCookies = [];
    $this->profileUser = staffProfileAccount('staff');
    $this->profileCsrf = staffProfileLogin($this, $this->profileCookies, $this->profileUser);
});

it('allows authenticated staff to read their own profile without staff.view permission', function () {
    $response = staffProfileRequest($this, $this->profileCookies, 'GET', '/api/users/me');
    $response->assertOk()
        ->assertJsonPath('data.user.id', $this->profileUser->id)
        ->assertJsonPath('data.user.email', $this->profileUser->email)
        ->assertJsonPath('data.user.role', 'staff')
        ->assertJsonPath('data.user.is_active', true);

    foreach (['password', 'remember_token', 'access_version', 'email_verified_at', 'password_confirmation'] as $field) {
        $response->assertJsonMissingPath('data.user.'.$field);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('allows staff to update their own permitted profile fields', function () {
    $response = staffProfileRequest($this, $this->profileCookies, 'PATCH', '/api/users/me', [
        'name' => 'Updated Profile',
        'phone' => '+8801712345678',
        'avatar_path' => 'avatars/staff-profile.png',
    ], $this->profileCsrf);

    $response->assertOk()
        ->assertJsonPath('data.user.id', $this->profileUser->id)
        ->assertJsonPath('data.user.name', 'Updated Profile')
        ->assertJsonPath('data.user.phone', '+8801712345678')
        ->assertJsonPath('data.user.avatar_path', 'avatars/staff-profile.png');

    $this->assertDatabaseHas('users', [
        'id' => $this->profileUser->id,
        'name' => 'Updated Profile',
        'phone' => '+8801712345678',
        'avatar_path' => 'avatars/staff-profile.png',
    ]);
});

it('allows a nullable phone number to be cleared', function () {
    $this->profileUser->forceFill(['phone' => '+8801812345678'])->save();
    staffProfileRequest($this, $this->profileCookies, 'PATCH', '/api/users/me', [
        'phone' => null,
    ], $this->profileCsrf)->assertOk()->assertJsonPath('data.user.phone', null);

    $this->assertDatabaseHas('users', ['id' => $this->profileUser->id, 'phone' => null]);
});

it('rejects forbidden self-profile fields without changing account data', function (string $field, mixed $value) {
    $before = $this->profileUser->fresh()->getRawOriginal();
    $response = staffProfileRequest($this, $this->profileCookies, 'PATCH', '/api/users/me', [
        'name' => 'Should Not Be Saved',
        $field => $value,
    ], $this->profileCsrf);
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect($this->profileUser->fresh()->getRawOriginal())->toBe($before);
})->with([
    'email' => ['email', 'new-email@example.test'],
    'password' => ['password', 'ChangedPassword@123'],
    'password confirmation' => ['password_confirmation', 'ChangedPassword@123'],
    'role' => ['role', 'admin'],
    'permissions' => ['permissions', ['staff.manage']],
    'account status' => ['is_active', false],
    'account ID' => ['id', 99999],
    'verification' => ['email_verified_at', '2026-10-19 12:00:00'],
    'access version' => ['access_version', 0],
    'unexpected field' => ['unexpected_field', 'value'],
]);

it('rejects invalid self-profile values without partial updates', function (array $payload, string $field) {
    $before = $this->profileUser->fresh()->getRawOriginal();
    if ($field === 'phone' && ($payload['phone'] ?? null) === '+8801912345678') {
        staffProfileAccount('staff', ['phone' => '+8801912345678']);
    }
    staffProfileRequest($this, $this->profileCookies, 'PATCH', '/api/users/me', array_merge([
        'name' => 'Should Not Be Saved',
    ], $payload), $this->profileCsrf)
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect($this->profileUser->fresh()->getRawOriginal())->toBe($before);
})->with([
    'name too long' => [['name' => str_repeat('N', 151)], 'name'],
    'invalid phone' => [['phone' => '01712345678'], 'phone'],
    'duplicate phone' => [['phone' => '+8801912345678'], 'phone'],
]);

it('does not expose another staff member through the own-profile endpoint', function () {
    $other = staffProfileAccount('staff', ['email' => 'other-profile@example.test']);
    $response = staffProfileRequest($this, $this->profileCookies, 'GET', '/api/users/me', [
        'id' => $other->id,
    ]);
    $response->assertUnprocessable();
    expect($response->json('data.user.id'))->not->toBe($other->id);
});

it('rejects unauthenticated own-profile reads and writes', function () {
    $cookies = [];
    staffProfileRequest($this, $cookies, 'GET', '/api/users/me')->assertUnauthorized();
    staffProfileRequest($this, $cookies, 'PATCH', '/api/users/me', [
        'name' => 'Unauthorized Change',
    ])->assertUnauthorized();
    $this->assertDatabaseHas('users', [
        'id' => $this->profileUser->id,
        'name' => 'Profile Operator',
    ]);
});

it('does not allow customer sessions to access staff self-profile endpoints', function () {
    $customer = staffProfileAccount('customer', ['email' => 'customer-profile@example.test']);
    DB::table('customers')->insert(['user_id' => $customer->id]);
    $cookies = [];
    $csrfResponse = staffProfileRequest($this, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();
    $login = staffProfileRequest($this, $cookies, 'POST', '/api/auth/customer/login', [
        'email' => $customer->email,
        'password' => 'OriginalPassword@123',
    ], $csrfResponse->json('data.csrf_token'));
    $login->assertOk();
    $csrf = $login->json('data.csrf_token');

    staffProfileRequest($this, $cookies, 'GET', '/api/users/me')->assertUnauthorized();
    staffProfileRequest($this, $cookies, 'PATCH', '/api/users/me', [
        'name' => 'Customer Escalation',
    ], $csrf)->assertUnauthorized();
    $this->assertDatabaseHas('users', ['id' => $customer->id, 'name' => 'Profile Operator']);
});
