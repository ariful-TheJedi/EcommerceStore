<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

function customerStatusAccount(array $fields = []): Customer
{
    $user = User::factory()->create([
        'role' => 'customer',
        ...$fields,
    ]);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerStatusLogin(
    $test,
    User $user,
    string $guard = 'customer',
    ?string $fingerprint = null,
): void {
    Auth::forgetGuards();

    $test->actingAs($user, $guard)->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => $fingerprint
            ?? app(AuthService::class)->fingerprint($user),
    ]);
}

function customerStatusStaff(
    $test,
    array $permissions = ['customers.view', 'customers.manage'],
): User {
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => $permissions,
    ]);

    customerStatusLogin($test, $staff, 'staff');

    return $staff;
}

it('blocks a customer and records the responsible staff member and reason', function () {
    $customer = customerStatusAccount();
    $staff = customerStatusStaff($this);

    $reason = 'Repeated delivery abuse';

    $this->postJson('/api/customers/'.$customer->id.'/block', [
        'reason' => $reason,
    ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.customer.status', 'blocked')
        ->assertJsonMissingPath('data.customer.blocked_reason')
        ->assertJsonMissingPath('data.customer.blocked_by');

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'blocked_by' => $staff->id,
        'blocked_reason' => $reason,
    ]);

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'actor_id' => $staff->id,
        'action' => 'blocked',
        'reason' => $reason,
    ]);

    expect((bool) $customer->user->fresh()->is_active)->toBeFalse();

    $this->getJson('/api/customers/'.$customer->id)
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'blocked')
        ->assertDontSee($reason);
});

it('rejects blocked customer login and invalidates the previous authentication fingerprint', function () {
    $customer = customerStatusAccount([
        'password' => 'CorrectPassword@123',
    ]);

    $oldFingerprint = app(AuthService::class)->fingerprint($customer->user);

    customerStatusStaff($this);

    $this->postJson('/api/customers/'.$customer->id.'/block', [
        'reason' => 'Account security review',
    ])->assertOk();

    $freshUser = $customer->user->fresh();

    expect(app(AuthService::class)->fingerprint($freshUser))
        ->not->toBe($oldFingerprint);

    customerStatusLogin($this, $freshUser, 'customer', $oldFingerprint);

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    $this->postJson('/api/auth/customer/login', [
        'email' => $freshUser->email,
        'password' => 'CorrectPassword@123',
    ])->assertUnauthorized();
});

it('unblocks a customer without restoring the authentication fingerprint from before blocking', function () {
    $customer = customerStatusAccount();

    $oldFingerprint = app(AuthService::class)->fingerprint($customer->user);

    $blocker = customerStatusStaff($this);

    $this->postJson('/api/customers/'.$customer->id.'/block', [
        'reason' => 'Pending review',
    ])->assertOk();

    $reviewer = customerStatusStaff($this);

    $this->postJson('/api/customers/'.$customer->id.'/unblock', [
        'reason' => 'Review completed',
    ])
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active');

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'actor_id' => $blocker->id,
        'action' => 'blocked',
        'reason' => 'Pending review',
    ]);

    $this->assertDatabaseHas('customer_status_history', [
        'customer_id' => $customer->id,
        'actor_id' => $reviewer->id,
        'action' => 'unblocked',
        'reason' => 'Review completed',
    ]);

    $freshUser = $customer->user->fresh();

    expect((bool) $freshUser->is_active)->toBeTrue();
    expect(app(AuthService::class)->fingerprint($freshUser))
        ->not->toBe($oldFingerprint);

    customerStatusLogin($this, $freshUser, 'customer', $oldFingerprint);

    $this->getJson('/api/customer/profile')->assertUnauthorized();

    customerStatusLogin($this, $freshUser);

    $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertJsonPath('data.customer.status', 'active');
});

it('requires valid reasons for blocking and unblocking without partial changes', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this);

    foreach (['block', 'unblock'] as $action) {
        if ($action === 'unblock') {
            $this->postJson('/api/customers/'.$customer->id.'/block', [
                'reason' => 'Prepare blocked account',
            ])->assertOk();
        }

        $userBefore = $customer->user->fresh()->getRawOriginal();
        $profileBefore = $customer->fresh()->getRawOriginal();

        $historyBefore = DB::table('customer_status_history')
            ->where('customer_id', $customer->id)
            ->count();

        foreach ([
            [],
            ['reason' => '   '],
            ['reason' => str_repeat('R', 1001)],
        ] as $payload) {
            $this->postJson(
                '/api/customers/'.$customer->id.'/'.$action,
                $payload,
            )
                ->assertUnprocessable()
                ->assertJsonValidationErrors('reason');

            expect($customer->user->fresh()->getRawOriginal())
                ->toBe($userBefore);

            expect($customer->fresh()->getRawOriginal())
                ->toBe($profileBefore);

            expect(
                DB::table('customer_status_history')
                    ->where('customer_id', $customer->id)
                    ->count(),
            )->toBe($historyBefore);
        }
    }
});

it('accepts reasons at the maximum supported length', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this);

    foreach (['block' => 'blocked', 'unblock' => 'unblocked'] as $route => $action) {
        $reason = str_repeat('R', 1000);

        $this->postJson('/api/customers/'.$customer->id.'/'.$route, [
            'reason' => $reason,
        ])->assertOk();

        $this->assertDatabaseHas('customer_status_history', [
            'customer_id' => $customer->id,
            'action' => $action,
            'reason' => $reason,
        ]);
    }
});

it('paginates status history and scopes it to the selected customer', function () {
    $customer = customerStatusAccount();
    $other = customerStatusAccount();

    customerStatusStaff($this);

    foreach (['block', 'unblock', 'block'] as $action) {
        $this->postJson('/api/customers/'.$customer->id.'/'.$action, [
            'reason' => 'Target '.$action,
        ])->assertOk();
    }

    $this->postJson('/api/customers/'.$other->id.'/block', [
        'reason' => 'Foreign confidential reason',
    ])->assertOk();

    $path = '/api/customers/'.$customer->id.'/status-history';

    $this->getJson($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(3, 'data.history')
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 3)
        ->assertDontSee('Foreign confidential reason');

    foreach ([1 => 2, 2 => 1, 3 => 0] as $page => $count) {
        $this->getJson($path.'?per_page=2&page='.$page)
            ->assertOk()
            ->assertJsonCount($count, 'data.history')
            ->assertJsonPath('data.pagination.current_page', $page)
            ->assertJsonPath('data.pagination.total', 3)
            ->assertJsonPath('data.pagination.last_page', 2);
    }
});

it('requires manage permission for status changes and view permission for history', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this, ['customers.view']);

    $this->getJson('/api/customers/'.$customer->id.'/status-history')
        ->assertOk();

    foreach (['block', 'unblock'] as $action) {
        $this->postJson('/api/customers/'.$customer->id.'/'.$action, [
            'reason' => 'Unauthorized transition',
        ])->assertForbidden();
    }

    customerStatusStaff($this, []);

    $this->getJson('/api/customers/'.$customer->id.'/status-history')
        ->assertForbidden();

    expect((bool) $customer->user->fresh()->is_active)->toBeTrue();

    expect(
        DB::table('customer_status_history')
            ->where('customer_id', $customer->id)
            ->count(),
    )->toBe(0);
});

it('requires both customer view and notes permissions for internal notes', function () {
    $customer = customerStatusAccount();
    $path = '/api/customers/'.$customer->id.'/notes';

    foreach ([
        [],
        ['customers.view'],
        ['customers.notes'],
        ['customers.manage'],
    ] as $permissions) {
        customerStatusStaff($this, $permissions);

        $this->getJson($path)->assertForbidden();

        $this->postJson($path, [
            'body' => 'Unauthorized internal note',
        ])->assertForbidden();
    }

    expect(
        DB::table('customer_notes')
            ->where('customer_id', $customer->id)
            ->count(),
    )->toBe(0);
});

it('creates internal notes with the responsible staff member and paginates only owned customer notes', function () {
    $customer = customerStatusAccount();
    $other = customerStatusAccount();

    $staff = customerStatusStaff($this, [
        'customers.view',
        'customers.notes',
    ]);

    $path = '/api/customers/'.$customer->id.'/notes';

    foreach (['First internal note', 'Second internal note'] as $body) {
        $id = $this->postJson($path, ['body' => $body])
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->json('data.note_id');

        expect($id)->not->toBeNull();

        $this->assertDatabaseHas('customer_notes', [
            'id' => $id,
            'customer_id' => $customer->id,
            'actor_id' => $staff->id,
            'body' => $body,
        ]);
    }

    $this->postJson('/api/customers/'.$other->id.'/notes', [
        'body' => 'Foreign private note',
    ])->assertCreated();

    $response = $this->getJson($path)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(2, 'data.notes')
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 2)
        ->assertDontSee('Foreign private note');

    $bodies = array_column($response->json('data.notes'), 'body');
    sort($bodies);

    expect($bodies)->toBe(['First internal note', 'Second internal note']);

    $seen = [];

    foreach ([1, 2] as $page) {
        $pageResponse = $this->getJson($path.'?per_page=1&page='.$page)
            ->assertOk()
            ->assertJsonCount(1, 'data.notes')
            ->assertJsonPath('data.pagination.current_page', $page)
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.pagination.last_page', 2);

        $seen[] = $pageResponse->json('data.notes.0.body');
    }

    sort($seen);

    expect($seen)->toBe($bodies);

    $this->getJson($path.'?per_page=1&page=3')
        ->assertOk()
        ->assertJsonCount(0, 'data.notes');
});

it('validates note bodies and accepts the maximum supported length', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this, [
        'customers.view',
        'customers.notes',
    ]);

    $path = '/api/customers/'.$customer->id.'/notes';

    foreach ([
        [],
        ['body' => '   '],
        ['body' => str_repeat('N', 5001)],
    ] as $payload) {
        $this->postJson($path, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('body');
    }

    expect(
        DB::table('customer_notes')
            ->where('customer_id', $customer->id)
            ->count(),
    )->toBe(0);

    $body = str_repeat('N', 5000);

    $this->postJson($path, ['body' => $body])->assertCreated();

    $this->assertDatabaseHas('customer_notes', [
        'customer_id' => $customer->id,
        'body' => $body,
    ]);
});

it('keeps internal notes out of profiles and denies customer access to staff notes and history', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this, [
        'customers.view',
        'customers.notes',
    ]);

    $secret = 'Confidential staff-only customer observation';

    $this->postJson('/api/customers/'.$customer->id.'/notes', [
        'body' => $secret,
    ])->assertCreated();

    $this->getJson('/api/customers/'.$customer->id)
        ->assertOk()
        ->assertDontSee($secret)
        ->assertJsonMissingPath('data.customer.notes');

    customerStatusLogin($this, $customer->user);

    $this->getJson('/api/customer/profile')
        ->assertOk()
        ->assertDontSee($secret)
        ->assertJsonMissingPath('data.customer.notes');

    $this->getJson('/api/customers/'.$customer->id.'/notes')
        ->assertUnauthorized();

    $this->postJson('/api/customers/'.$customer->id.'/notes', [
        'body' => 'Customer cannot add internal notes',
    ])->assertUnauthorized();

    $this->getJson('/api/customers/'.$customer->id.'/status-history')
        ->assertUnauthorized();
});

it('validates pagination on notes and status history', function () {
    $customer = customerStatusAccount();

    customerStatusStaff($this, [
        'customers.view',
        'customers.notes',
    ]);

    foreach (['notes', 'status-history'] as $resource) {
        $path = '/api/customers/'.$customer->id.'/'.$resource;

        foreach ([
            [['page' => 0], 'page'],
            [['per_page' => 0], 'per_page'],
            [['per_page' => 101], 'per_page'],
        ] as [$query, $field]) {
            $this->getJson($path.'?'.http_build_query($query))
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
        }
    }
});

it('returns not found for status and note operations on missing customers', function () {
    customerStatusStaff($this, [
        'customers.view',
        'customers.manage',
        'customers.notes',
    ]);

    $missingId = ((int) Customer::max('id')) + 1000;
    $base = '/api/customers/'.$missingId;

    foreach (['block', 'unblock'] as $action) {
        $this->postJson($base.'/'.$action, [
            'reason' => 'Missing customer',
        ])->assertNotFound();
    }

    $this->getJson($base.'/status-history')->assertNotFound();
    $this->getJson($base.'/notes')->assertNotFound();

    $this->postJson($base.'/notes', [
        'body' => 'Missing customer note',
    ])->assertNotFound();
});
