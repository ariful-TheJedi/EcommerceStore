<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Independent helpers: safe when the entire Pest suite is run together.
function staffSecurityAccount(string $role = 'staff', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Security Test Operator',
        'email' => 'security-'.uniqid('', true).'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'permissions' => $role === 'staff' ? [] : null,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function staffSecurityCaptureCookies(TestResponse $response, array &$cookies): void
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

function staffSecurityRequest(TestCase $test, array &$cookies, string $method, string $url, array $body = [], ?string $csrf = null): TestResponse
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
        $url,
        $method === 'GET' ? $body : [],
        $cookies,
        [],
        $headers,
        $method === 'GET' ? null : json_encode($body, JSON_THROW_ON_ERROR),
    );
    staffSecurityCaptureCookies($response, $cookies);

    return $response;
}

function staffSecurityLogin(TestCase $test, array &$cookies, User $user): string
{
    $first = staffSecurityRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $first->assertOk();
    $csrf = $first->json('data.csrf_token');
    expect($csrf)->toBeString()->not->toBe('');

    $login = staffSecurityRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $csrf);
    $login->assertOk()->assertJsonPath('data.user.email', $user->email);
    $newToken = $login->json('data.csrf_token');
    expect($newToken)->toBeString()->not->toBe('');

    return $newToken;
}

function staffSecurityPatch($test, array &$cookies, string $csrf, User $target, array $data): TestResponse
{
    return staffSecurityRequest($test, $cookies, 'PATCH', '/api/users/'.$target->id, $data, $csrf);
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->securityAdmin = staffSecurityAccount('admin');
    $this->securityCookies = [];
    $this->securityCsrf = staffSecurityLogin($this, $this->securityCookies, $this->securityAdmin);
});

it('protects the final active admin against deactivation', function () {
    $before = $this->securityAdmin->fresh()->getRawOriginal();

    staffSecurityRequest($this, $this->securityCookies, 'POST', '/api/users/'.$this->securityAdmin->id.'/disable', [], $this->securityCsrf)
        ->assertStatus(409);

    expect($this->securityAdmin->fresh()->getRawOriginal())->toEqual($before);
});

it('protects the final active admin against demotion', function () {
    $before = $this->securityAdmin->fresh()->getRawOriginal();

    staffSecurityPatch($this, $this->securityCookies, $this->securityCsrf, $this->securityAdmin, ['role' => 'staff'])
        ->assertStatus(409);

    expect($this->securityAdmin->fresh()->getRawOriginal())->toEqual($before);
});

it('does not count disabled admins toward final active admin protection', function () {
    staffSecurityAccount('admin', ['is_active' => false]);

    staffSecurityRequest($this, $this->securityCookies, 'POST', '/api/users/'.$this->securityAdmin->id.'/disable', [], $this->securityCsrf)
        ->assertStatus(409);

    expect($this->securityAdmin->fresh()->is_active)->toBeTrue();
});

it('allows deactivating a different admin when another active admin remains', function () {
    $target = staffSecurityAccount('admin');

    staffSecurityRequest($this, $this->securityCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->securityCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', false);

    expect($target->fresh()->is_active)->toBeFalse();
    expect($this->securityAdmin->fresh()->is_active)->toBeTrue();
});

it('allows demoting a different admin while preserving the last active admin', function () {
    $target = staffSecurityAccount('admin');

    staffSecurityPatch($this, $this->securityCookies, $this->securityCsrf, $target, ['role' => 'staff'])
        ->assertOk()->assertJsonPath('data.user.role', 'staff');

    expect($target->fresh()->role)->toBe('staff');
    expect($this->securityAdmin->fresh()->role)->toBe('admin');
});

it('prevents self role and permission edits without the separate staff.assign_self authority', function () {
    // Test an otherwise authorized user who lacks explicit self-assignment authority.
    $manager = staffSecurityAccount('staff', [
        'permissions' => ['staff.manage', 'roles.assign', 'permissions.assign'],
    ]);
    $cookies = [];
    $csrf = staffSecurityLogin($this, $cookies, $manager);
    $before = $manager->fresh()->getRawOriginal();

    staffSecurityPatch($this, $cookies, $csrf, $manager, ['role' => 'admin'])->assertForbidden();
    staffSecurityPatch($this, $cookies, $csrf, $manager, ['permissions' => ['staff.assign_self']])->assertForbidden();

    expect($manager->fresh()->getRawOriginal())->toEqual($before);
});

it('denies a non-admin account manager access to a disabled admin', function () {
    $manager = staffSecurityAccount('staff', ['permissions' => ['staff.manage']]);
    $target = staffSecurityAccount('admin', ['is_active' => false]);
    $cookies = [];
    $csrf = staffSecurityLogin($this, $cookies, $manager);
    $before = $target->fresh()->getRawOriginal();

    staffSecurityPatch($this, $cookies, $csrf, $target, ['email' => 'takeover@example.test'])->assertForbidden();
    staffSecurityRequest($this, $cookies, 'POST', '/api/users/'.$target->id.'/reactivate', [], $csrf)
        ->assertForbidden();

    expect($target->fresh()->getRawOriginal())->toEqual($before);
});

it('blocks a manager from modifying or reactivating staff with greater assigned privileges', function () {
    $manager = staffSecurityAccount('staff', ['permissions' => ['staff.manage']]);
    $target = staffSecurityAccount('staff', [
        'is_active' => false,
        'permissions' => ['staff.manage', 'payments.reconcile'],
    ]);
    $cookies = [];
    $csrf = staffSecurityLogin($this, $cookies, $manager);
    $before = $target->fresh()->getRawOriginal();

    staffSecurityPatch($this, $cookies, $csrf, $target, ['email' => 'hijacked@example.test'])->assertForbidden();
    staffSecurityRequest($this, $cookies, 'POST', '/api/users/'.$target->id.'/reactivate', [], $csrf)
        ->assertForbidden();

    expect($target->fresh()->getRawOriginal())->toEqual($before);
});

it('rejects sensitive mass-assignment fields and never persists a partial profile update', function (array $extra) {
    $target = staffSecurityAccount('staff');
    $before = $target->fresh()->getRawOriginal();

    staffSecurityPatch($this, $this->securityCookies, $this->securityCsrf, $target, array_merge([
        'name' => 'Must Not Save',
    ], $extra))->assertUnprocessable();

    expect($target->fresh()->getRawOriginal())->toEqual($before);
})->with([
    'account ID' => [['id' => 999999]],
    'active status' => [['is_active' => false]],
    'access version' => [['access_version' => 1]],
    'password hash' => [['password' => 'InjectedPassword@123']],
    'email verification' => [['email_verified_at' => '2026-10-19 12:00:00']],
    'unknown field' => [['superuser' => true]],
]);

it('never returns password hashes, reset tokens or access versions from account responses', function () {
    $target = staffSecurityAccount('staff');
    $response = staffSecurityRequest($this, $this->securityCookies, 'GET', '/api/users/'.$target->id);
    $response->assertOk();

    foreach (['password', 'remember_token', 'password_hash', 'reset_token', 'access_version', 'authentication_fingerprint'] as $secret) {
        $response->assertJsonMissingPath('data.user.'.$secret);
    }
    expect($response->getContent())->not->toContain($target->getAuthPassword());
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('does not expose customer accounts through staff lookup', function () {
    $customer = staffSecurityAccount('customer');
    DB::table('customers')->insert(['user_id' => $customer->id]);

    staffSecurityRequest($this, $this->securityCookies, 'GET', '/api/users/'.$customer->id)
        ->assertNotFound();
});

it('does not register a destructive DELETE staff endpoint', function () {
    $target = staffSecurityAccount('staff');

    staffSecurityRequest($this, $this->securityCookies, 'DELETE', '/api/users/'.$target->id, [], $this->securityCsrf)
        ->assertMethodNotAllowed();

    $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => true]);
});
