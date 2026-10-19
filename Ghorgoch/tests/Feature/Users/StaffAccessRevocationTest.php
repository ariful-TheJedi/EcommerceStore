<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Unique prefix: this file works by itself and alongside the other Pest files.
function accessRevocationUser(string $role = 'staff', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Access Revocation Operator',
        'email' => 'access-'.uniqid('', true).'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'permissions' => $role === 'staff' ? [] : null,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function accessRevocationCapture(TestResponse $response, array &$cookies): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        $expires = $cookie->getExpiresTime();
        if ($cookie->isCleared() || ($expires !== 0 && $expires <= time())) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }
}

function accessRevocationRequest(TestCase $test, array &$cookies, string $method, string $uri, array $data = [], ?string $csrf = null): TestResponse
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
        $method === 'GET' ? $data : [],
        $cookies,
        [],
        $headers,
        $method === 'GET' ? null : json_encode($data, JSON_THROW_ON_ERROR),
    );
    accessRevocationCapture($response, $cookies);

    return $response;
}

function accessRevocationLogin(TestCase $test, array &$cookies, User $user): string
{
    $csrf = accessRevocationRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrf->assertOk();
    $initial = $csrf->json('data.csrf_token');
    expect($initial)->toBeString()->not->toBe('');

    $login = accessRevocationRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $initial);
    $login->assertOk()->assertJsonPath('data.user.email', $user->email);
    $token = $login->json('data.csrf_token');
    expect($token)->toBeString()->not->toBe('');

    return $token;
}

function accessRevocationDenied(TestResponse $response): void
{
    $response->assertUnauthorized();
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->revocationAdmin = accessRevocationUser('admin');
    $this->revocationCookies = [];
    $this->revocationCsrf = accessRevocationLogin($this, $this->revocationCookies, $this->revocationAdmin);
});

it('allows an active authenticated staff member to read their own profile', function () {
    accessRevocationRequest($this, $this->revocationCookies, 'GET', '/api/users/me')
        ->assertOk()
        ->assertJsonPath('data.user.id', $this->revocationAdmin->id);
});

it('deactivation increments the account access version and preserves the account', function () {
    $target = accessRevocationUser('staff');
    $before = (int) $target->access_version;

    accessRevocationRequest($this, $this->revocationCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->revocationCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', false);

    $fresh = $target->fresh();
    expect($fresh->is_active)->toBeFalse();
    expect((int) $fresh->access_version)->toBeGreaterThan($before);
    $this->assertDatabaseHas('users', ['id' => $target->id, 'role' => 'staff', 'is_active' => false]);
});

it('rejects login for disabled staff accounts', function () {
    $target = accessRevocationUser('staff', ['is_active' => false]);
    $cookies = [];
    $csrf = accessRevocationRequest($this, $cookies, 'GET', '/api/auth/csrf-token');
    $csrf->assertOk();

    $response = accessRevocationRequest($this, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $target->email,
        'password' => 'OriginalPassword@123',
    ], $csrf->json('data.csrf_token'));

    $response->assertUnauthorized();
    accessRevocationDenied(accessRevocationRequest($this, $cookies, 'GET', '/api/users/me'));
});

it('revokes a logged-in staff session after the account is disabled', function () {
    // The authenticated target disables an ordinary peer; a separate admin later disables the target.
    // Snapshot the target's cookies before the status change, then replay them.
    $target = accessRevocationUser('staff', ['permissions' => ['staff.view']]);
    $targetCookies = [];
    accessRevocationLogin($this, $targetCookies, $target);
    accessRevocationRequest($this, $targetCookies, 'GET', '/api/users/me')
        ->assertOk()->assertJsonPath('data.user.id', $target->id);

    // Perform the administrative action using a separate session cookie jar.
    accessRevocationRequest($this, $this->revocationCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->revocationCsrf)
        ->assertOk();

    accessRevocationDenied(accessRevocationRequest($this, $targetCookies, 'GET', '/api/users/me'));
    accessRevocationDenied(accessRevocationRequest($this, $targetCookies, 'GET', '/api/users'));
});

it('reactivation does not restore an earlier revoked session', function () {
    $target = accessRevocationUser('staff');
    $oldCookies = [];
    accessRevocationLogin($this, $oldCookies, $target);
    accessRevocationRequest($this, $oldCookies, 'GET', '/api/users/me')->assertOk();

    accessRevocationRequest($this, $this->revocationCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->revocationCsrf)
        ->assertOk();
    $disabledVersion = (int) $target->fresh()->access_version;

    accessRevocationRequest($this, $this->revocationCookies, 'POST', '/api/users/'.$target->id.'/reactivate', [], $this->revocationCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', true);

    expect((int) $target->fresh()->access_version)->toBeGreaterThan($disabledVersion);
    accessRevocationDenied(accessRevocationRequest($this, $oldCookies, 'GET', '/api/users/me'));
});

it('allows a reactivated account to establish a new session', function () {
    $target = accessRevocationUser('staff', ['is_active' => false]);

    accessRevocationRequest($this, $this->revocationCookies, 'POST', '/api/users/'.$target->id.'/reactivate', [], $this->revocationCsrf)
        ->assertOk();

    $newCookies = [];
    accessRevocationLogin($this, $newCookies, $target);
    accessRevocationRequest($this, $newCookies, 'GET', '/api/users/me')
        ->assertOk()->assertJsonPath('data.user.id', $target->id);
});

it('role changes increment the access version and invalidate the changed account session', function () {
    $target = accessRevocationUser('staff');
    $oldCookies = [];
    accessRevocationLogin($this, $oldCookies, $target);
    $version = (int) $target->access_version;

    accessRevocationRequest($this, $this->revocationCookies, 'PATCH', '/api/users/'.$target->id, [
        'role' => 'admin',
    ], $this->revocationCsrf)->assertOk()->assertJsonPath('data.user.role', 'admin');

    expect((int) $target->fresh()->access_version)->toBeGreaterThan($version);
    accessRevocationDenied(accessRevocationRequest($this, $oldCookies, 'GET', '/api/users/me'));
});

it('permission changes take effect without a cache and revoke the previous session', function () {
    $target = accessRevocationUser('staff', ['permissions' => ['staff.view']]);
    $oldCookies = [];
    accessRevocationLogin($this, $oldCookies, $target);
    accessRevocationRequest($this, $oldCookies, 'GET', '/api/users')->assertOk();
    $version = (int) $target->access_version;

    accessRevocationRequest($this, $this->revocationCookies, 'PATCH', '/api/users/'.$target->id, [
        'permissions' => [],
    ], $this->revocationCsrf)->assertOk();

    expect((int) $target->fresh()->access_version)->toBeGreaterThan($version);
    accessRevocationDenied(accessRevocationRequest($this, $oldCookies, 'GET', '/api/users'));

    $freshCookies = [];
    accessRevocationLogin($this, $freshCookies, $target);
    accessRevocationRequest($this, $freshCookies, 'GET', '/api/users')->assertForbidden();
    accessRevocationRequest($this, $freshCookies, 'GET', '/api/users/me')->assertOk();
});

it('changing a staff login email revokes the previous session and requires the new email on next login', function () {
    $target = accessRevocationUser('staff');
    $oldCookies = [];
    accessRevocationLogin($this, $oldCookies, $target);
    $version = (int) $target->access_version;
    $newEmail = 'new-access-'.uniqid().'@example.test';

    accessRevocationRequest($this, $this->revocationCookies, 'PATCH', '/api/users/'.$target->id, [
        'email' => $newEmail,
    ], $this->revocationCsrf)
        ->assertOk()->assertJsonPath('data.user.email', $newEmail);

    expect((int) $target->fresh()->access_version)->toBeGreaterThan($version);
    accessRevocationDenied(accessRevocationRequest($this, $oldCookies, 'GET', '/api/users/me'));

    $target->refresh();
    $newCookies = [];
    accessRevocationLogin($this, $newCookies, $target);
    accessRevocationRequest($this, $newCookies, 'GET', '/api/users/me')
        ->assertOk()->assertJsonPath('data.user.email', $newEmail);
});
