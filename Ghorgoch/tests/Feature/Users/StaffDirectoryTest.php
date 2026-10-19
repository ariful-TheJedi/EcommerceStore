<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// File-local helpers: never depend on AuthFlowTest or another Users test file.
function staffDirectoryAccount(string $role, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Directory Operator',
        'email' => 'directory-'.uniqid().'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function staffDirectoryCookies(TestResponse $response, array &$cookies): void
{
    foreach ($response->headers->getCookies() as $cookie) {
        if ($cookie->isCleared()) {
            unset($cookies[$cookie->getName()]);
        } else {
            $cookies[$cookie->getName()] = $cookie->getValue();
        }
    }
}

function staffDirectoryRequest(TestCase $test, array &$cookies, string $method, string $uri, array $payload = [], ?string $csrf = null): TestResponse
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
    staffDirectoryCookies($response, $cookies);

    return $response;
}

function staffDirectoryLogin(TestCase $test, array &$cookies, User $user): void
{
    $csrfResponse = staffDirectoryRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();
    $token = $csrfResponse->json('data.csrf_token');
    expect($token)->toBeString()->not->toBe('');

    $login = staffDirectoryRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $token);
    $login->assertOk()->assertJsonPath('data.user.email', $user->email);
}

function staffDirectoryIds(TestResponse $response): array
{
    return array_map('intval', array_column($response->json('data.staff'), 'id'));
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->directoryCookies = [];
    $this->directoryAdmin = staffDirectoryAccount('admin');
    staffDirectoryLogin($this, $this->directoryCookies, $this->directoryAdmin);
});

it('lists staff with the documented response structure, ordered by ID and default pagination', function () {
    $a = staffDirectoryAccount('staff', ['name' => 'First Operator']);
    $b = staffDirectoryAccount('staff', ['name' => 'Second Operator']);
    $customer = staffDirectoryAccount('customer', ['name' => 'Customer Must Be Hidden']);
    DB::table('customers')->insert(['user_id' => $customer->id]);

    $response = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users');
    $response->assertOk()
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 20)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertJsonPath('data.pagination.last_page', 1);

    $ids = staffDirectoryIds($response);
    expect($ids)->toBe([$this->directoryAdmin->id, $a->id, $b->id]);
    expect($ids)->not->toContain($customer->id);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('paginates staff consistently without skipping or repeating accounts', function () {
    for ($i = 1; $i <= 5; $i++) {
        staffDirectoryAccount('staff', ['name' => 'Pagination Operator '.$i]);
    }

    $page1 = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?per_page=2&page=1');
    $page2 = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?per_page=2&page=2');
    $page3 = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?per_page=2&page=3');
    $page4 = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?per_page=2&page=4');

    foreach ([$page1, $page2, $page3, $page4] as $index => $response) {
        $response->assertOk()
            ->assertJsonPath('data.pagination.current_page', $index + 1)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 6)
            ->assertJsonPath('data.pagination.last_page', 3);
    }

    expect(array_map('count', [$page1->json('data.staff'), $page2->json('data.staff'), $page3->json('data.staff'), $page4->json('data.staff')]))
        ->toBe([2, 2, 2, 0]);

    $allIds = array_merge(staffDirectoryIds($page1), staffDirectoryIds($page2), staffDirectoryIds($page3));
    expect($allIds)->toBe(array_values(array_unique($allIds)));
    $sorted = $allIds;
    sort($sorted);
    expect($allIds)->toBe($sorted);
});

it('searches staff by name, email, and phone without including unrelated records', function (string $query) {
    $matching = staffDirectoryAccount('staff', [
        'name' => 'Distinctive Warehouse Operator',
        'email' => 'unique-warehouse@example.test',
        'phone' => '+8801712345678',
    ]);
    staffDirectoryAccount('staff', [
        'name' => 'Sales Clerk',
        'email' => 'sales-clerk@example.test',
        'phone' => '+8801812345678',
    ]);

    $response = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?'.http_build_query(['search' => $query]));
    $response->assertOk()->assertJsonPath('data.pagination.total', 1);
    expect(staffDirectoryIds($response))->toBe([$matching->id]);
})->with([
    'name substring' => 'Warehouse',
    'name case insensitive' => 'warehouse',
    'email substring' => 'unique-warehouse@',
    'email case insensitive' => 'UNIQUE-WAREHOUSE@',
    'phone substring' => '1712345678',
]);

it('filters by role and status and combines both filters', function () {
    $activeStaff = staffDirectoryAccount('staff', ['name' => 'Active Staff']);
    $disabledStaff = staffDirectoryAccount('staff', ['name' => 'Disabled Staff', 'is_active' => false]);
    $disabledAdmin = staffDirectoryAccount('admin', ['name' => 'Disabled Admin', 'is_active' => false]);

    $cases = [
        ['role' => 'staff', 'expected' => [$activeStaff->id, $disabledStaff->id]],
        ['status' => 'disabled', 'expected' => [$disabledStaff->id, $disabledAdmin->id]],
        ['role' => 'staff', 'status' => 'active', 'expected' => [$activeStaff->id]],
        ['role' => 'admin', 'status' => 'active', 'expected' => [$this->directoryAdmin->id]],
        ['role' => 'admin', 'status' => 'disabled', 'expected' => [$disabledAdmin->id]],
    ];

    foreach ($cases as $case) {
        $expected = $case['expected'];
        unset($case['expected']);
        $response = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?'.http_build_query($case));
        $response->assertOk()->assertJsonPath('data.pagination.total', count($expected));
        expect(staffDirectoryIds($response))->toBe($expected);
    }
});

it('returns an empty directory result for a search without matches', function () {
    staffDirectoryAccount('staff', ['name' => 'Regular Staff']);

    staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?search=definitely-no-match-829373')
        ->assertOk()
        ->assertJsonPath('data.staff', [])
        ->assertJsonPath('data.pagination.total', 0);
});

it('rejects invalid pagination and filter parameters', function (string $query, string $field) {
    $response = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?'.$query);
    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
})->with([
    'page zero' => ['page=0', 'page'],
    'negative page' => ['page=-1', 'page'],
    'non-integer page' => ['page=abc', 'page'],
    'per page zero' => ['per_page=0', 'per_page'],
    'per page above limit' => ['per_page=101', 'per_page'],
    'non-integer per page' => ['per_page=abc', 'per_page'],
    'overlong search' => ['search='.str_repeat('x', 151), 'search'],
    'invalid role' => ['role=customer', 'role'],
    'invalid status' => ['status=pending', 'status'],
    'unexpected field' => ['sort=password', 'sort'],
]);

it('does not include sensitive credentials or auth internals in staff directory responses', function () {
    staffDirectoryAccount('staff', ['name' => 'Private Operator']);

    $response = staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users');
    $response->assertOk();

    $staff = $response->json('data.staff');
    expect($staff)->toBeArray()->not->toBeEmpty();
    foreach ($staff as $user) {
        foreach (['password', 'remember_token', 'access_version', 'email_verified_at', 'reset_token', 'password_confirmation'] as $key) {
            expect($user)->not->toHaveKey($key);
        }
        expect($user)->toHaveKeys(['id', 'name', 'email', 'role', 'is_active', 'permissions']);
    }
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('allows staff with staff.view permission to list staff but denies staff without that permission', function () {
    $viewer = staffDirectoryAccount('staff', ['permissions' => ['staff.view']]);
    $withoutPermission = staffDirectoryAccount('staff', ['permissions' => []]);

    $viewerCookies = [];
    staffDirectoryLogin($this, $viewerCookies, $viewer);
    staffDirectoryRequest($this, $viewerCookies, 'GET', '/api/users')->assertOk();

    $noPermissionCookies = [];
    staffDirectoryLogin($this, $noPermissionCookies, $withoutPermission);
    staffDirectoryRequest($this, $noPermissionCookies, 'GET', '/api/users')->assertForbidden();
});

it('rejects unauthenticated directory access', function () {
    $cookies = [];
    staffDirectoryRequest($this, $cookies, 'GET', '/api/users')->assertUnauthorized();
});

it('does not modify accounts when validating directory queries', function () {
    $target = staffDirectoryAccount('staff', ['name' => 'Untouched Operator']);
    $before = $target->fresh()->getRawOriginal();
    $count = DB::table('users')->count();

    staffDirectoryRequest($this, $this->directoryCookies, 'GET', '/api/users?role=customer')
        ->assertUnprocessable();

    expect($target->fresh()->getRawOriginal())->toEqual($before);
    expect(DB::table('users')->count())->toBe($count);
});
