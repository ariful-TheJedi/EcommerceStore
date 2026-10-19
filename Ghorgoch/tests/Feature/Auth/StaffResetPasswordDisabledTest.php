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
        'auth.email_enabled' => false,
        'session.driver' => 'array',
    ]);

    Mail::fake();
    Notification::fake();
});

function staffResetDisabledPayload(array $overrides = []): array
{
    return array_replace([
        'email' => 'staff-reset@example.test',
        'token' => str_repeat('a', 64),
        'password' => 'NewStaffPassword@456',
        'password_confirmation' => 'NewStaffPassword@456',
    ], $overrides);
}

it('returns JSON forbidden while password recovery is disabled', function () {
    $response = $this->postJson(
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload(),
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

it('preserves staff and admin accounts and their reset tokens', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'staff-reset@example.test',
        'password' => Hash::make('OldStaffPassword@123'),
        'role' => $role,
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
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload(),
    )->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($originalAccount);

    $tokenAfter = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    expect($tokenAfter)->toBe($originalToken);

    expect(Hash::check(
        'OldStaffPassword@123',
        $user->fresh()->password,
    ))->toBeTrue();

    expect(Hash::check(
        'NewStaffPassword@456',
        $user->fresh()->password,
    ))->toBeFalse();

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin']);

it('does not disclose account eligibility while recovery is disabled', function () {
    foreach ([
        ['active-staff@example.test', 'staff', true, now()],
        ['active-admin@example.test', 'admin', true, now()],
        ['disabled-staff@example.test', 'staff', false, now()],
        ['disabled-admin@example.test', 'admin', false, now()],
        ['unverified-staff@example.test', 'staff', true, null],
        ['unverified-admin@example.test', 'admin', true, null],
        ['customer@example.test', 'customer', true, now()],
    ] as [$email, $role, $active, $verifiedAt]) {
        User::factory()->create([
            'email' => $email,
            'role' => $role,
            'is_active' => $active,
            'email_verified_at' => $verifiedAt,
        ]);
    }

    $baseline = $this->postJson(
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload([
            'email' => 'unknown@example.test',
        ]),
    );

    $baseline->assertForbidden();

    foreach ([
        'active-staff@example.test',
        'active-admin@example.test',
        'disabled-staff@example.test',
        'disabled-admin@example.test',
        'unverified-staff@example.test',
        'unverified-admin@example.test',
        'customer@example.test',
    ] as $email) {
        $this->postJson(
            '/api/auth/staff/reset-password',
            staffResetDisabledPayload(['email' => $email]),
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
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload(),
    )->assertForbidden();

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
    expect(DB::table('password_reset_tokens')->count())
        ->toBe($tokensBefore);
});

it('does not establish an authenticated session', function (string $role) {
    User::factory()->create([
        'email' => 'staff-reset@example.test',
        'role' => $role,
    ]);

    $this->postJson(
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload(),
    )->assertForbidden();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('does not expose submitted passwords or tokens', function () {
    $payload = staffResetDisabledPayload();

    $response = $this->postJson(
        '/api/auth/staff/reset-password',
        $payload,
    );

    $response->assertForbidden();

    expect($response->getContent())
        ->not->toContain($payload['password'])
        ->not->toContain($payload['token']);
});

it('does not delete or modify existing sessions when reset is blocked', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'staff-reset@example.test',
        'role' => $role,
    ]);

    $sessionId = 'staff-blocked-reset-session';

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
        '/api/auth/staff/reset-password',
        staffResetDisabledPayload(),
    )->assertForbidden();

    $after = (array) DB::table('sessions')
        ->where('id', $sessionId)
        ->first();

    expect($after)->toBe($before);
})->with(['staff', 'admin']);
