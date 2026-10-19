<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

uses(LazilyRefreshDatabase::class);

beforeEach(function () {
    expect(app()->environment())->toBe('testing');
    expect(config('database.default'))->toBe('sqlite');
    expect(config('database.connections.sqlite.database'))->toBe(':memory:');

    config([
        'auth.customer_enabled' => true,
        'auth.email_enabled' => false,
        'session.driver' => 'array',
    ]);

    Mail::fake();
    Notification::fake();
});

function customerResetDisabledPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'customer-reset@example.test',
        'token' => str_repeat('a', 64),
        'password' => 'NewCustomerPassword@456',
        'password_confirmation' => 'NewCustomerPassword@456',
    ], $overrides);
}

it('returns JSON forbidden while password recovery is disabled', function () {
    $response = $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload(),
    );

    $response
        ->assertForbidden()
        ->assertJsonStructure(['message', 'data']);

    expect(strtolower(
        $response->headers->get('Content-Type', '')
    ))->toStartWith('application/json');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('preserves the password and existing reset token', function () {
    $user = User::factory()->create([
        'email' => 'customer-reset@example.test',
        'password' => Hash::make('OldCustomerPassword@123'),
        'role' => 'customer',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $originalAccount = $user->fresh()->getRawOriginal();

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => Hash::make(str_repeat('a', 64)),
        'created_at' => now(),
    ]);

    $originalToken = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload(),
    )->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($originalAccount);

    $tokenAfter = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    expect($tokenAfter)->toBe($originalToken);

    expect(Hash::check(
        'OldCustomerPassword@123',
        $user->fresh()->password,
    ))->toBeTrue();

    expect(Hash::check(
        'NewCustomerPassword@456',
        $user->fresh()->password,
    ))->toBeFalse();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('returns the same forbidden response regardless of account eligibility', function () {
    foreach ([
        ['active@example.test', 'customer', true, now()],
        ['disabled@example.test', 'customer', false, now()],
        ['unverified@example.test', 'customer', true, null],
        ['staff@example.test', 'staff', true, now()],
        ['admin@example.test', 'admin', true, now()],
    ] as [$email, $role, $active, $verifiedAt]) {
        User::factory()->create([
            'email' => $email,
            'role' => $role,
            'is_active' => $active,
            'email_verified_at' => $verifiedAt,
        ]);
    }

    $baseline = $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload([
            'email' => 'unknown@example.test',
        ]),
    );

    $baseline->assertForbidden();

    foreach ([
        'active@example.test',
        'disabled@example.test',
        'unverified@example.test',
        'staff@example.test',
        'admin@example.test',
    ] as $email) {
        $this->postJson(
            '/api/auth/customer/reset-password',
            customerResetDisabledPayload(['email' => $email]),
        )
            ->assertForbidden()
            ->assertExactJson($baseline->json());
    }

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('does not create accounts profiles or reset tokens', function () {
    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();
    $tokensBefore = DB::table('password_reset_tokens')->count();

    $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload(),
    )->assertForbidden();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
    expect(DB::table('password_reset_tokens')->count())
        ->toBe($tokensBefore);
});

it('does not establish an authenticated session', function () {
    User::factory()->create([
        'email' => 'customer-reset@example.test',
        'role' => 'customer',
    ]);

    $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload(),
    )->assertForbidden();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();
});

it('does not expose submitted passwords or tokens in the response', function () {
    $payload = customerResetDisabledPayload();

    $response = $this->postJson(
        '/api/auth/customer/reset-password',
        $payload,
    );

    $response->assertForbidden();

    expect($response->getContent())
        ->not->toContain($payload['password'])
        ->not->toContain($payload['token']);
});

it('does not delete existing sessions when reset is blocked', function () {
    $user = User::factory()->create([
        'email' => 'customer-reset@example.test',
        'role' => 'customer',
    ]);

    $sessionId = 'customer-blocked-reset-session';

    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test device',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->timestamp,
    ]);

    $before = (array) DB::table('sessions')
        ->where('id', $sessionId)
        ->first();

    $this->postJson(
        '/api/auth/customer/reset-password',
        customerResetDisabledPayload(),
    )->assertForbidden();

    $after = (array) DB::table('sessions')
        ->where('id', $sessionId)
        ->first();

    expect($after)->toBe($before);
});
