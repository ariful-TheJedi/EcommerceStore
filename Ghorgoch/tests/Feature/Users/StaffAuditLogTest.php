<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

// Self-contained helpers; prefixed to avoid collisions with other Pest files.
function staffAuditAccount(string $role = 'staff', array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'name' => 'Audit Test Operator',
        'email' => 'audit-'.uniqid('', true).'@example.test',
        'password' => Hash::make('OriginalPassword@123'),
        'role' => $role,
        'permissions' => $role === 'staff' ? [] : null,
        'is_active' => true,
        'email_verified_at' => null,
    ], $attributes));
}

function staffAuditCaptureCookies(TestResponse $response, array &$cookies): void
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

function staffAuditRequest(
    TestCase $test,
    array &$cookies,
    string $method,
    string $uri,
    array $data = [],
    ?string $csrf = null
): TestResponse {
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
    staffAuditCaptureCookies($response, $cookies);

    return $response;
}

function staffAuditLogin(TestCase $test, array &$cookies, User $user): string
{
    $csrfResponse = staffAuditRequest($test, $cookies, 'GET', '/api/auth/csrf-token');
    $csrfResponse->assertOk();

    $login = staffAuditRequest($test, $cookies, 'POST', '/api/auth/staff/login', [
        'email' => $user->email,
        'password' => 'OriginalPassword@123',
    ], $csrfResponse->json('data.csrf_token'));

    $login->assertOk()->assertJsonPath('data.user.email', $user->email);

    $token = $login->json('data.csrf_token');
    expect($token)->toBeString()->not->toBe('');

    return $token;
}

function staffAuditEntries(User $staff)
{
    return DB::table('staff_activity')
        ->where('staff_id', $staff->id)
        ->orderBy('id')
        ->get();
}

function staffAuditDecode($value): array
{
    if (is_array($value)) {
        return $value;
    }

    if (is_string($value)) {
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    return [];
}

beforeEach(function () {
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    $this->auditAdmin = staffAuditAccount('admin');
    $this->auditCookies = [];
    $this->auditCsrf = staffAuditLogin($this, $this->auditCookies, $this->auditAdmin);
});

it('records staff creation with authenticated actor, target, and timestamp', function () {
    $response = staffAuditRequest($this, $this->auditCookies, 'POST', '/api/users', [
        'name' => 'New Audit Staff',
        'email' => 'new-audit@example.test',
        'password' => 'OriginalPassword@123',
        'password_confirmation' => 'OriginalPassword@123',
        'role' => 'staff',
    ], $this->auditCsrf)->assertCreated();

    $id = $response->json('data.user.id');

    $this->assertDatabaseHas('staff_activity', [
        'actor_id' => $this->auditAdmin->id,
        'staff_id' => $id,
        'action' => 'created',
    ]);

    $entry = DB::table('staff_activity')
        ->where('staff_id', $id)
        ->where('action', 'created')
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->created_at)->not->toBeNull();
});

it('records account profile updates and identifies the correct actor', function () {
    $target = staffAuditAccount('staff');
    $before = staffAuditEntries($target)->count();

    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'name' => 'Changed By Admin',
    ], $this->auditCsrf)->assertOk();

    $entries = staffAuditEntries($target);
    expect($entries)->toHaveCount($before + 1);
    expect((int) $entries->last()->actor_id)->toBe($this->auditAdmin->id);
    expect((int) $entries->last()->staff_id)->toBe($target->id);
});

it('records disabling and reactivating a staff account', function () {
    $target = staffAuditAccount('staff');
    $before = staffAuditEntries($target)->count();

    staffAuditRequest($this, $this->auditCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->auditCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', false);

    staffAuditRequest($this, $this->auditCookies, 'POST', '/api/users/'.$target->id.'/reactivate', [], $this->auditCsrf)
        ->assertOk()->assertJsonPath('data.user.is_active', true);

    $changes = staffAuditEntries($target)->slice($before)->values();
    expect($changes)->toHaveCount(2);
    foreach ($changes as $change) {
        expect((int) $change->actor_id)->toBe($this->auditAdmin->id);
        expect((int) $change->staff_id)->toBe($target->id);
        expect($change->created_at)->not->toBeNull();
    }
});

it('records both role and permission changes', function () {
    $target = staffAuditAccount('staff', ['permissions' => ['catalog.view']]);
    $before = staffAuditEntries($target)->count();

    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'permissions' => ['inventory.adjust'],
    ], $this->auditCsrf)->assertOk();

    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'role' => 'admin',
    ], $this->auditCsrf)->assertOk();

    $changes = staffAuditEntries($target)->slice($before)->values();
    expect($changes->count())->toBeGreaterThanOrEqual(2);
    expect($changes->every(fn ($entry) => (int) $entry->actor_id === $this->auditAdmin->id))->toBeTrue();
});

it('does not write audit entries for rejected profile updates', function () {
    $target = staffAuditAccount('staff');
    $before = staffAuditEntries($target)->count();
    $original = $target->fresh()->getRawOriginal();

    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'name' => 'Should Not Persist',
        'is_active' => false,
    ], $this->auditCsrf)->assertUnprocessable();

    expect(staffAuditEntries($target))->toHaveCount($before);
    expect($target->fresh()->getRawOriginal())->toEqual($original);
});

it('does not write audit entries when an unauthorized staff member attempts a change', function () {
    $target = staffAuditAccount('staff');
    $unauthorized = staffAuditAccount('staff', ['permissions' => []]);
    $cookies = [];
    $csrf = staffAuditLogin($this, $cookies, $unauthorized);
    $before = staffAuditEntries($target)->count();

    staffAuditRequest($this, $cookies, 'PATCH', '/api/users/'.$target->id, [
        'name' => 'Unauthorized Change',
    ], $csrf)->assertForbidden();

    expect(staffAuditEntries($target))->toHaveCount($before);
    expect($target->fresh()->name)->not->toBe('Unauthorized Change');
});

it('returns an authorized, paginated audit history for the requested staff member', function () {
    $target = staffAuditAccount('staff');

    foreach (range(1, 4) as $number) {
        staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
            'name' => 'Audit Revision '.$number,
        ], $this->auditCsrf)->assertOk();
    }

    $response = staffAuditRequest($this, $this->auditCookies, 'GET',
        '/api/users/'.$target->id.'/activity?page=1&per_page=2'
    );

    $response->assertOk()
        ->assertJsonCount(2, 'data.activity')
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 2);

    expect($response->json('data.pagination.total'))->toBeGreaterThanOrEqual(4);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->headers->get('Cache-Control'))->toContain('private');
});

it('rejects invalid audit history pagination', function (string $query) {
    $target = staffAuditAccount('staff');

    staffAuditRequest($this, $this->auditCookies, 'GET',
        '/api/users/'.$target->id.'/activity?'.$query
    )->assertUnprocessable();
})->with([
    'page zero' => 'page=0',
    'negative page' => 'page=-2',
    'per_page zero' => 'per_page=0',
    'per_page above limit' => 'per_page=101',
    'unexpected filter' => 'sort=desc',
]);

it('prevents staff without staff.view from reading activity history', function () {
    $target = staffAuditAccount('staff');
    $viewer = staffAuditAccount('staff', ['permissions' => []]);
    $cookies = [];
    staffAuditLogin($this, $cookies, $viewer);

    staffAuditRequest($this, $cookies, 'GET', '/api/users/'.$target->id.'/activity')
        ->assertForbidden();
});

it('allows staff.view to read history without staff.manage', function () {
    $target = staffAuditAccount('staff');
    $viewer = staffAuditAccount('staff', ['permissions' => ['staff.view']]);
    $cookies = [];
    staffAuditLogin($this, $cookies, $viewer);

    staffAuditRequest($this, $cookies, 'GET', '/api/users/'.$target->id.'/activity')
        ->assertOk()->assertJsonStructure(['data' => ['activity', 'pagination']]);
});

it('requires staff authentication for activity history', function () {
    $target = staffAuditAccount('staff');
    $cookies = [];

    staffAuditRequest($this, $cookies, 'GET', '/api/users/'.$target->id.'/activity')
        ->assertUnauthorized();
});

it('returns 404 for a missing staff account and a customer account', function () {
    $customer = staffAuditAccount('customer');
    $missingId = User::max('id') + 1000;

    staffAuditRequest($this, $this->auditCookies, 'GET', '/api/users/'.$missingId.'/activity')
        ->assertNotFound();

    staffAuditRequest($this, $this->auditCookies, 'GET', '/api/users/'.$customer->id.'/activity')
        ->assertNotFound();
});

it('preserves the account and its audit history after deactivation', function () {
    $target = staffAuditAccount('staff');

    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'name' => 'Historical Operator',
    ], $this->auditCsrf)->assertOk();

    staffAuditRequest($this, $this->auditCookies, 'POST', '/api/users/'.$target->id.'/disable', [], $this->auditCsrf)
        ->assertOk();

    $this->assertDatabaseHas('users', ['id' => $target->id, 'is_active' => false]);

    $history = staffAuditRequest($this, $this->auditCookies, 'GET', '/api/users/'.$target->id.'/activity');
    $history->assertOk();
    expect(count($history->json('data.activity')))->toBeGreaterThanOrEqual(2);
});

it('does not expose password hashes or authentication secrets in activity responses', function () {
    $target = staffAuditAccount('staff');
    staffAuditRequest($this, $this->auditCookies, 'PATCH', '/api/users/'.$target->id, [
        'name' => 'Safe Name',
    ], $this->auditCsrf)->assertOk();

    $response = staffAuditRequest($this, $this->auditCookies, 'GET', '/api/users/'.$target->id.'/activity');
    $response->assertOk();

    $json = $response->getContent();
    expect($json)->not->toContain($target->password);
    expect($json)->not->toContain('OriginalPassword@123');

    // Security-sensitive fields must not be included in before/after audit snapshots.
    foreach (staffAuditEntries($target) as $entry) {
        $before = staffAuditDecode($entry->before);
        $after = staffAuditDecode($entry->after);
        foreach (['password', 'remember_token', 'access_version', 'password_reset_token'] as $secret) {
            expect($before)->not->toHaveKey($secret);
            expect($after)->not->toHaveKey($secret);
        }
    }
});
