<?php

use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('returns JSON forbidden while email features are disabled', function () {
    $response = $this->postJson('/api/auth/staff/forgot-password', [
        'email' => 'unknown@example.test',
    ]);

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
        '/api/auth/staff/forgot-password',
        ['email' => 'unknown@example.test'],
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
        $this->postJson('/api/auth/staff/forgot-password', [
            'email' => $email,
        ])
            ->assertForbidden()
            ->assertExactJson($baseline->json());
    }

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
});

it('does not create tokens or modify staff and admin accounts', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'staff-recovery@example.test',
        'role' => $role,
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $original = $user->fresh()->getRawOriginal();

    $usersBefore = DB::table('users')->count();
    $customersBefore = DB::table('customers')->count();
    $tokensBefore = DB::table('password_reset_tokens')->count();

    $this->postJson('/api/auth/staff/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    expect($user->fresh()->getRawOriginal())->toBe($original);

    expect(DB::table('users')->count())->toBe($usersBefore);
    expect(DB::table('customers')->count())->toBe($customersBefore);
    expect(DB::table('password_reset_tokens')->count())
        ->toBe($tokensBefore);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin']);

it('preserves existing reset tokens while recovery is disabled', function (
    string $role
) {
    $user = User::factory()->create([
        'email' => 'existing-staff-token@example.test',
        'role' => $role,
    ]);

    DB::table('password_reset_tokens')->insert([
        'email' => $user->email,
        'token' => password_hash(
            str_repeat('a', 64),
            PASSWORD_BCRYPT,
        ),
        'created_at' => now(),
    ]);

    $before = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    $this->postJson('/api/auth/staff/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    $after = (array) DB::table('password_reset_tokens')
        ->where('email', $user->email)
        ->first();

    expect($after)->toBe($before);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
    Notification::assertNothingSent();
})->with(['staff', 'admin']);

it('does not establish an authenticated session', function (string $role) {
    $user = User::factory()->create([
        'email' => 'no-staff-login@example.test',
        'role' => $role,
    ]);

    $this->postJson('/api/auth/staff/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    $this->getJson('/api/auth/staff/me')
        ->assertUnauthorized();

    $this->getJson('/api/auth/customer/me')
        ->assertUnauthorized();
})->with(['staff', 'admin']);

it('does not invalidate existing sessions when recovery is blocked', function () {
    $user = User::factory()->create([
        'email' => 'session-owner@example.test',
        'role' => 'staff',
    ]);

    $sessionId = 'staff-recovery-existing-session';

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

    $this->postJson('/api/auth/staff/forgot-password', [
        'email' => $user->email,
    ])->assertForbidden();

    $after = (array) DB::table('sessions')
        ->where('id', $sessionId)
        ->first();

    expect($after)->toBe($before);
});
