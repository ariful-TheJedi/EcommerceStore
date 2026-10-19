<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Prefixed helpers keep this file independent from the other Pest test files.
function rolePermissionUser(string $role = 'staff', array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Permission Test Account',
        'email' => 'permission-'.uniqid().'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
        'permissions' => $role === 'staff' ? [] : null,
    ], $overrides));
}

function rolePermissionCollectCookies(TestResponse $response, array &$cookies): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared()) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }
}

function rolePermissionRequest(TestCase $test, array &$cookies, string $method, string $url, array $body = [], ?string $token = null): TestResponse
{
    Auth::forgetGuards();
    app()->forgetInstance('auth.driver');
    app('session')->forgetDrivers();
    app()->forgetInstance('session.store');

    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token !== null) {
        $headers['HTTP_X_CSRF_TOKEN'] = $token;
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
    rolePermissionCollectCookies($response, $cookies);

    return $response;
}

function rolePermissionLogin(TestCase $test, array &$cookies, User $user): string
{
    $csrfResponse = rolePermissionRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();
    $initialToken = $csrfResponse->json('data.csrf_token');
    expect($initialToken)->toBeString()->not->toBe('');

    $login = rolePermissionRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $initialToken);
    $login->assertOk()->assertJsonPath('data.user.email', $user->email);
    $token = $login->json('data.csrf_token');
    expect($token)->toBeString()->not->toBe('');

    return $token;
}

function rolePermissionPatch(TestCase $test, array &$cookies, string $csrf, User $target, array $payload): TestResponse
{
    return rolePermissionRequest($test, $cookies, 'PATCH', '/api/users/'.$target->id, $payload, $csrf);
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->permissionAdmin = rolePermissionUser('admin');
    $this->permissionCookies = [];
    $this->permissionCsrf = rolePermissionLogin($this, $this->permissionCookies, $this->permissionAdmin);
});

it('returns the fixed roles and supported permission catalog only to authorized admins', function () {
    $response = rolePermissionRequest($this, $this->permissionCookies, 'GET', '/api/users/capabilities');
    $response->assertOk();
    expect($response->json('data.roles'))->toBeArray()->toContain('admin', 'staff');

    $permissions = $response->json('data.permissions');
    expect($permissions)->toBeArray();
    // Some implementations expose permission metadata objects rather than strings.
    $names = array_map(static fn ($permission) => is_array($permission) ? ($permission['name'] ?? null) : $permission, $permissions);
    foreach (['staff.view', 'staff.manage', 'roles.assign', 'permissions.assign', 'staff.assign_self',
        'catalog.view', 'inventory.adjust', 'orders.process', 'payments.reconcile'] as $name) {
        expect($names)->toContain($name);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('denies capabilities to an unauthenticated user and an ordinary staff member', function () {
    $anonymousCookies = [];
    rolePermissionRequest($this, $anonymousCookies, 'GET', '/api/users/capabilities')->assertUnauthorized();

    $staff = rolePermissionUser('staff', ['permissions' => ['staff.view']]);
    $cookies = [];
    rolePermissionLogin($this, $cookies, $staff);
    rolePermissionRequest($this, $cookies, 'GET', '/api/users/capabilities')->assertForbidden();
});

it('lets an admin assign a supported role and explicit permissions to another staff member', function () {
    $target = rolePermissionUser('staff');
    rolePermissionPatch($this, $this->permissionCookies, $this->permissionCsrf, $target, [
        'role' => 'staff',
        'permissions' => ['catalog.view', 'inventory.adjust'],
    ])->assertOk()
        ->assertJsonPath('data.user.role', 'staff')
        ->assertJsonPath('data.user.permissions', ['catalog.view', 'inventory.adjust']);

    expect($target->fresh()->role)->toBe('staff');
    expect($target->fresh()->permissions)->toEqual(['catalog.view', 'inventory.adjust']);
});

it('lets an admin promote another staff member to an admin', function () {
    $target = rolePermissionUser('staff');
    rolePermissionPatch($this, $this->permissionCookies, $this->permissionCsrf, $target, [
        'role' => 'admin',
    ])->assertOk()->assertJsonPath('data.user.role', 'admin');

    expect($target->fresh()->role)->toBe('admin');
});

it('does not grant permissions to a staff member by default', function () {
    $staff = rolePermissionUser('staff', ['permissions' => []]);
    $cookies = [];
    rolePermissionLogin($this, $cookies, $staff);

    rolePermissionRequest($this, $cookies, 'GET', '/api/users')->assertForbidden();
    rolePermissionRequest($this, $cookies, 'GET', '/api/users/capabilities')->assertForbidden();
});

it('lets staff.view read staff without granting staff.manage', function () {
    $reader = rolePermissionUser('staff', ['permissions' => ['staff.view']]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $reader);
    $target = rolePermissionUser('staff');

    rolePermissionRequest($this, $cookies, 'GET', '/api/users')->assertOk();
    rolePermissionRequest($this, $cookies, 'GET', '/api/users/'.$target->id)->assertOk();
    rolePermissionPatch($this, $cookies, $csrf, $target, ['name' => 'Forbidden Change'])->assertForbidden();
    expect($target->fresh()->name)->toBe('Permission Test Account');
});

it('allows staff.manage to edit ordinary staff but not assign roles', function () {
    $manager = rolePermissionUser('staff', ['permissions' => ['staff.manage']]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $manager);
    $target = rolePermissionUser('staff');

    rolePermissionPatch($this, $cookies, $csrf, $target, ['name' => 'Managed Staff'])
        ->assertOk()->assertJsonPath('data.user.name', 'Managed Staff');

    rolePermissionPatch($this, $cookies, $csrf, $target, ['role' => 'admin'])->assertForbidden();
    expect($target->fresh()->role)->toBe('staff');
});

it('prevents staff.manage alone from assigning permissions', function () {
    $manager = rolePermissionUser('staff', ['permissions' => ['staff.manage', 'catalog.view']]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $manager);
    $target = rolePermissionUser('staff');
    $original = $target->fresh()->getRawOriginal();

    rolePermissionPatch($this, $cookies, $csrf, $target, ['permissions' => ['catalog.view']])
        ->assertForbidden();
    expect($target->fresh()->getRawOriginal())->toEqual($original);
});

it('prevents an ordinary staff member from editing or disabling an admin', function () {
    $manager = rolePermissionUser('staff', ['permissions' => ['staff.manage']]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $manager);
    $adminTarget = rolePermissionUser('admin');
    $original = $adminTarget->fresh()->getRawOriginal();

    rolePermissionPatch($this, $cookies, $csrf, $adminTarget, ['name' => 'Hijacked'])->assertForbidden();
    rolePermissionRequest($this, $cookies, 'POST', '/api/users/'.$adminTarget->id.'/disable', [], $csrf)
        ->assertForbidden();
    expect($adminTarget->fresh()->getRawOriginal())->toEqual($original);
});

it('rejects unsupported permission names and duplicate permissions without partial updates', function (array $permissions, array $fields) {
    $target = rolePermissionUser('staff', ['permissions' => ['catalog.view']]);
    $original = $target->fresh()->getRawOriginal();

    rolePermissionPatch($this, $this->permissionCookies, $this->permissionCsrf, $target, [
        'name' => 'Should Not Persist',
        'permissions' => $permissions,
    ])->assertUnprocessable()->assertJsonValidationErrors($fields);

    expect($target->fresh()->getRawOriginal())->toEqual($original);
})->with([
    'unknown permission' => [['root.access'], ['permissions.0']],
    'duplicate permission' => [['catalog.view', 'catalog.view'], ['permissions.0', 'permissions.1']],
    'mixed valid and invalid' => [['catalog.view', 'root.access'], ['permissions.1']],
]);

it('rejects unsupported roles without modifying the account', function () {
    $target = rolePermissionUser('staff');
    $original = $target->fresh()->getRawOriginal();

    rolePermissionPatch($this, $this->permissionCookies, $this->permissionCsrf, $target, [
        'role' => 'customer',
    ])->assertUnprocessable()->assertJsonValidationErrors(['role']);

    expect($target->fresh()->getRawOriginal())->toEqual($original);
});

it('prevents an ordinary staff member from changing their own role or permissions', function () {
    $staff = rolePermissionUser('staff', ['permissions' => ['staff.manage']]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $staff);
    $original = $staff->fresh()->getRawOriginal();

    rolePermissionPatch($this, $cookies, $csrf, $staff, ['role' => 'admin'])->assertForbidden();
    rolePermissionPatch($this, $cookies, $csrf, $staff, ['permissions' => ['roles.assign']])->assertForbidden();
    expect($staff->fresh()->getRawOriginal())->toEqual($original);
});

it('does not accept caller-provided permission strings as authorization', function () {
    $staff = rolePermissionUser('staff', ['permissions' => []]);
    $cookies = [];
    $csrf = rolePermissionLogin($this, $cookies, $staff);
    $before = DB::table('users')->count();

    rolePermissionRequest($this, $cookies, 'POST', '/api/users?staff_permission=staff.manage', [
        'name' => 'Unauthorized Account',
        'email' => 'unauthorized-permission@example.test',
        'password' => 'OriginalPassword@123',
        'password_confirmation' => 'OriginalPassword@123',
        'role' => 'staff',
        'staff_permission' => 'staff.manage',
    ], $csrf)->assertForbidden();

    expect(DB::table('users')->count())->toBe($before);
});
