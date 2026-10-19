<?php

/*
|--------------------------------------------------------------------------
| CSRF token endpoint
|--------------------------------------------------------------------------
|
| Endpoint: GET /api/auth/csrf-token
| Expected token location: data.csrf_token
|
| Uses Tests\TestCase through tests/Pest.php.
|
*/

it('provides a csrf token without requiring authentication', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => ['csrf_token'],
        ]);
});

it('returns a nonempty string csrf token', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertOk();

    $token = $response->json('data.csrf_token');

    expect($token)->toBeString();
    expect(trim($token))->not->toBe('');
});

it('returns JSON content', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertOk();

    expect(strtolower(
        $response->headers->get('Content-Type', '')
    ))->toStartWith('application/json');
});

it('stores the returned csrf token in the session', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertOk();

    $token = $response->json('data.csrf_token');

    expect($token)->toBeString();
    expect($token)->not->toBe('');

    $response->assertSessionHas('_token', $token);
});

it('issues the configured session cookie', function () {
    $cookieName = config('session.cookie');

    expect($cookieName)->toBeString();
    expect($cookieName)->not->toBe('');

    $response = $this->getJson('/api/auth/csrf-token');

    $response
        ->assertOk()
        ->assertCookie($cookieName);
});

it('keeps the session cookie inaccessible to browser JavaScript', function () {
    $response = $this->getJson('/api/auth/csrf-token');

    $response->assertOk();

    $cookieName = config('session.cookie');

    $sessionCookie = collect($response->headers->getCookies())
        ->first(
            fn ($cookie) => $cookie->getName() === $cookieName
        );

    expect($sessionCookie)->not->toBeNull();
    expect($sessionCookie->isHttpOnly())->toBeTrue();
});

it('rejects unexpected query parameters', function (array $query) {
    $url = '/api/auth/csrf-token?'.http_build_query($query);

    $this->getJson($url)->assertUnprocessable();
})->with([
    'arbitrary field' => [['unexpected' => 'value']],
    'injected user id' => [['user_id' => 1]],
    'injected role' => [['role' => 'super_admin']],
    'injected permissions' => [['permissions' => ['*']]],
    'injected active status' => [['is_active' => 1]],
    'client supplied token' => [['csrf_token' => 'attacker-controlled']],
    'nested unexpected field' => [['data' => ['role' => 'super_admin']]],
]);

it('does not replace the session token with a client supplied query token', function () {
    $attackerToken = 'attacker-controlled-token';

    $response = $this->getJson(
        '/api/auth/csrf-token?'.http_build_query([
            'csrf_token' => $attackerToken,
        ])
    );

    $response->assertUnprocessable();

    // Rejection may still initialize a session, but must never adopt this token.
    expect(session()->token())->not->toBe($attackerToken);
});
