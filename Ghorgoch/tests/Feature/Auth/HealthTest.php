<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;

/*
|--------------------------------------------------------------------------
| Auth module health endpoint
|--------------------------------------------------------------------------
|
| Endpoint: GET /api/auth/health
|
| Requires tests/Feature to use Tests\TestCase in tests/Pest.php.
| No database records or real credentials are needed.
|
*/

it('allows access without authentication', function () {
    $this->getJson('/api/auth/health')
        ->assertOk();
});

it('returns a valid JSON object', function () {
    $response = $this->getJson('/api/auth/health');

    $response->assertOk();

    $contentType = $response->headers->get('Content-Type', '');

    expect(strtolower($contentType))
        ->toStartWith('application/json');

    $body = json_decode(
        $response->getContent(),
        associative: false,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($body)->toBeInstanceOf(stdClass::class);
});

it('remains accessible when an unrelated invalid bearer token is supplied', function () {
    // This public endpoint must not require bearer authentication.
    $this->withHeaders([
        'Authorization' => 'Bearer invalid-intruder-token',
    ])
        ->getJson('/api/auth/health')
        ->assertOk();
});

it('supports a HEAD request without returning a response body', function () {
    // Laravel normally supports HEAD automatically for GET routes.
    $response = $this->call(
        'HEAD',
        '/api/auth/health',
        server: ['HTTP_ACCEPT' => 'application/json'],
    );

    $response->assertOk();

    expect($response->getContent())->toBe('');
});

it('rejects unsupported HTTP methods', function (string $method) {
    /*
     * Disable only Laravel's standard CSRF middleware here so that this
     * checks method restrictions independently of CSRF validation.
     *
     * CSRF behavior needs separate tests because Laravel normally
     * bypasses its CSRF checks while running automated tests.
     */
    $this->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ]);

    $response = $this->json($method, '/api/auth/health');

    $response->assertStatus(405);

    $allowedMethods = array_map(
        static fn (string $value): string => strtoupper(trim($value)),
        explode(',', $response->headers->get('Allow', '')),
    );

    expect($allowedMethods)->toContain('GET');
    expect($allowedMethods)->not->toContain($method);
})->with([
    'POST method' => ['POST'],
    'PUT method' => ['PUT'],
    'PATCH method' => ['PATCH'],
    'DELETE method' => ['DELETE'],
]);

it('does not expose sensitive internal field names in its JSON response', function () {
    $response = $this->getJson('/api/auth/health');

    $response->assertOk();

    $payload = $response->json();

    expect($payload)->toBeArray();

    $forbiddenKeys = [
        'password',
        'password_hash',
        'password_confirmation',
        'current_password',
        'remember_token',
        'reset_token',
        'verification_token',
        'session_id',
        'app_key',
        'db_password',
        'database_url',
        'mail_password',
        'smtp_password',
        'stack',
        'stack_trace',
        'trace',
        'exception',
    ];

    $inspect = function (array $data) use (&$inspect, $forbiddenKeys): void {
        foreach ($data as $key => $value) {
            if (is_string($key)) {
                expect(strtolower($key))
                    ->not->toBeIn($forbiddenKeys);
            }

            if (is_array($value)) {
                $inspect($value);
            }
        }
    };

    $inspect($payload);
});
