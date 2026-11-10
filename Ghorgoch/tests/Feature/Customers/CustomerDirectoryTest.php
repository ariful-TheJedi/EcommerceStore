<?php

declare(strict_types=1);

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;

uses(LazilyRefreshDatabase::class);

function customerDirectoryAccount(array $fields = []): Customer
{
    $user = User::factory()->create([
        'role' => 'customer',
        'phone' => null,
        ...$fields,
    ]);

    return Customer::factory()
        ->create(['user_id' => $user->id])
        ->load('user');
}

function customerDirectoryStaff($test): User
{
    $staff = User::factory()->create([
        'role' => 'staff',
        'permissions' => ['customers.view', 'customers.manage'],
    ]);

    Auth::forgetGuards();

    $test->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

it('returns an empty directory with pagination when there are no customers', function () {
    customerDirectoryStaff($this);

    $this->getJson('/api/customers')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(0, 'data.customers')
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonStructure([
            'data' => [
                'customers',
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                ],
            ],
        ]);
});

it('lists customer profiles and excludes staff accounts', function () {
    $first = customerDirectoryAccount(['name' => 'First Customer']);
    $second = customerDirectoryAccount(['name' => 'Second Customer']);

    User::factory()->create([
        'role' => 'staff',
        'email' => 'excluded-staff@example.test',
    ]);

    customerDirectoryStaff($this);

    $response = $this->getJson('/api/customers')
        ->assertOk()
        ->assertJsonCount(2, 'data.customers')
        ->assertJsonPath('data.pagination.total', 2)
        ->assertDontSee('excluded-staff@example.test');

    $ids = array_column($response->json('data.customers'), 'id');

    sort($ids);

    $expected = [$first->id, $second->id];
    sort($expected);

    expect($ids)->toBe($expected);
});

it('searches by name email phone and exact customer profile identifier', function () {
    $target = customerDirectoryAccount([
        'name' => 'Rahim Unique Customer',
        'email' => 'rahim.directory@example.test',
        'phone' => '+8801712345678',
    ]);

    customerDirectoryAccount([
        'name' => 'Unrelated Person',
        'email' => 'unrelated@example.test',
    ]);

    customerDirectoryStaff($this);

    foreach ([
        'Rahim Unique',
        'RAHIM.DIRECTORY@EXAMPLE.TEST',
        '+8801712345678',
        (string) $target->id,
    ] as $search) {
        $query = http_build_query([
            'search' => $search,
            'per_page' => 10,
            'page' => 1,
        ]);

        $this->getJson('/api/customers?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.id', $target->id)
            ->assertJsonPath('data.pagination.total', 1);
    }
});

it('returns an empty result for a search without matches', function () {
    customerDirectoryAccount(['name' => 'Existing Customer']);

    customerDirectoryStaff($this);

    $this->getJson('/api/customers?'.http_build_query([
        'search' => 'definitely-no-matching-customer',
    ]))
        ->assertOk()
        ->assertJsonCount(0, 'data.customers')
        ->assertJsonPath('data.pagination.total', 0);
});

it('does not return staff accounts when their contact details match the search', function () {
    customerDirectoryAccount([
        'name' => 'Ordinary Customer',
        'email' => 'ordinary@example.test',
    ]);

    User::factory()->create([
        'role' => 'staff',
        'name' => 'Staff Directory Needle',
        'email' => 'staff-directory-needle@example.test',
        'phone' => '+8801912345678',
    ]);

    customerDirectoryStaff($this);

    foreach ([
        'Staff Directory Needle',
        'staff-directory-needle@example.test',
        '+8801912345678',
    ] as $search) {
        $this->getJson('/api/customers?'.http_build_query([
            'search' => $search,
        ]))
            ->assertOk()
            ->assertJsonCount(0, 'data.customers')
            ->assertJsonPath('data.pagination.total', 0);
    }
});

it('paginates without duplicate or missing customer profiles', function () {
    $customers = collect();

    foreach (range(1, 5) as $number) {
        $customers->push(customerDirectoryAccount([
            'name' => 'Pagination Customer '.$number,
        ]));
    }

    customerDirectoryStaff($this);

    $seen = [];

    foreach ([1 => 2, 2 => 2, 3 => 1] as $page => $count) {
        $response = $this->getJson(
            '/api/customers?per_page=2&page='.$page,
        )
            ->assertOk()
            ->assertJsonCount($count, 'data.customers')
            ->assertJsonPath('data.pagination.current_page', $page)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.last_page', 3);

        $seen = [
            ...$seen,
            ...array_column($response->json('data.customers'), 'id'),
        ];
    }

    expect(count(array_unique($seen)))->toBe(5);

    $expected = $customers->pluck('id')->all();

    sort($seen);
    sort($expected);

    expect($seen)->toBe($expected);

    $this->getJson('/api/customers?per_page=2&page=4')
        ->assertOk()
        ->assertJsonCount(0, 'data.customers')
        ->assertJsonPath('data.pagination.current_page', 4)
        ->assertJsonPath('data.pagination.total', 5)
        ->assertJsonPath('data.pagination.last_page', 3);
});

it('uses fifteen records per page by default and accepts the maximum page size', function () {
    foreach (range(1, 16) as $number) {
        customerDirectoryAccount([
            'name' => 'Default Pagination '.$number,
        ]);
    }

    customerDirectoryStaff($this);

    $this->getJson('/api/customers')
        ->assertOk()
        ->assertJsonCount(15, 'data.customers')
        ->assertJsonPath('data.pagination.current_page', 1)
        ->assertJsonPath('data.pagination.per_page', 15)
        ->assertJsonPath('data.pagination.total', 16)
        ->assertJsonPath('data.pagination.last_page', 2);

    $this->getJson('/api/customers?page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data.customers')
        ->assertJsonPath('data.pagination.current_page', 2);

    $this->getJson('/api/customers?per_page=100')
        ->assertOk()
        ->assertJsonCount(16, 'data.customers')
        ->assertJsonPath('data.pagination.per_page', 100)
        ->assertJsonPath('data.pagination.last_page', 1);
});

it('filters active blocked and closed customer profiles', function () {
    $active = customerDirectoryAccount(['name' => 'Active Customer']);
    $blocked = customerDirectoryAccount(['name' => 'Blocked Customer']);
    $closed = customerDirectoryAccount(['name' => 'Closing Customer']);

    customerDirectoryStaff($this);

    $this->postJson('/api/customers/'.$blocked->id.'/block', [
        'reason' => 'Directory status test',
    ])->assertOk();

    $this->postJson('/api/customers/'.$closed->id.'/close', [
        'reason' => 'Directory closure test',
    ])->assertOk();

    foreach ([
        'active' => $active->id,
        'blocked' => $blocked->id,
        'closed' => $closed->id,
    ] as $status => $id) {
        $this->getJson('/api/customers?status='.$status)
            ->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.id', $id)
            ->assertJsonPath('data.customers.0.status', $status)
            ->assertJsonPath('data.pagination.total', 1);
    }

    $this->getJson('/api/customers')
        ->assertOk()
        ->assertJsonCount(3, 'data.customers')
        ->assertJsonPath('data.pagination.total', 3);
});

it('combines search and status filters before calculating pagination', function () {
    $active = customerDirectoryAccount([
        'name' => 'Shared Search Customer',
    ]);

    $blocked = customerDirectoryAccount([
        'name' => 'Shared Search Customer',
    ]);

    customerDirectoryAccount([
        'name' => 'Different Customer',
    ]);

    customerDirectoryStaff($this);

    $this->postJson('/api/customers/'.$blocked->id.'/block', [
        'reason' => 'Filter combination test',
    ])->assertOk();

    foreach ([
        'active' => $active->id,
        'blocked' => $blocked->id,
    ] as $status => $id) {
        $query = http_build_query([
            'search' => 'Shared Search',
            'status' => $status,
            'per_page' => 1,
            'page' => 1,
        ]);

        $this->getJson('/api/customers?'.$query)
            ->assertOk()
            ->assertJsonCount(1, 'data.customers')
            ->assertJsonPath('data.customers.0.id', $id)
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.pagination.last_page', 1);
    }

    $this->getJson('/api/customers?'.http_build_query([
        'search' => 'Shared Search',
        'status' => 'active',
        'per_page' => 1,
        'page' => 2,
    ]))
        ->assertOk()
        ->assertJsonCount(0, 'data.customers')
        ->assertJsonPath('data.pagination.total', 1);
});

dataset('customers.directory.invalid_queries', [
    'zero page' => [['page' => 0], 'page'],
    'negative page' => [['page' => -1], 'page'],
    'fractional page' => [['page' => '1.5'], 'page'],
    'nonnumeric page' => [['page' => 'invalid'], 'page'],
    'zero page size' => [['per_page' => 0], 'per_page'],
    'oversized page size' => [['per_page' => 101], 'per_page'],
    'fractional page size' => [['per_page' => '2.5'], 'per_page'],
    'invalid status' => [['status' => 'deleted'], 'status'],
]);

it('validates directory query parameters', function (
    array $query,
    string $field,
) {
    customerDirectoryStaff($this);

    $this->getJson('/api/customers?'.http_build_query($query))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with('customers.directory.invalid_queries');

it('exposes only the permitted profile fields and hides internal status details', function () {
    $customer = customerDirectoryAccount([
        'name' => 'Privacy Customer',
        'email' => 'privacy@example.test',
    ]);

    customerDirectoryStaff($this);

    $reason = 'Confidential directory status reason';

    $this->postJson('/api/customers/'.$customer->id.'/block', [
        'reason' => $reason,
    ])->assertOk();

    $response = $this->getJson('/api/customers')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonCount(1, 'data.customers')
        ->assertDontSee($reason);

    $expectedKeys = [
        'id',
        'name',
        'email',
        'phone',
        'avatar_path',
        'date_of_birth',
        'status',
        'email_verified',
    ];

    $actualKeys = array_keys($response->json('data.customers.0'));

    sort($expectedKeys);
    sort($actualKeys);

    expect($actualKeys)->toBe($expectedKeys);

    $this->assertDatabaseHas('customers', [
        'id' => $customer->id,
        'blocked_reason' => $reason,
    ]);
});
