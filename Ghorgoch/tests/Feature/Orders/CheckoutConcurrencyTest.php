<?php

declare(strict_types=1);

/**
 * Location: tests/Feature/Orders/CheckoutConcurrencyTest.php
 * Run from your Laravel project root (PowerShell):
 *   $env:ORDERS_PG_DATABASE='your_test_database'
 *   .\vendor\bin\pest tests/Feature/Orders/CheckoutConcurrencyTest.php
 * Optional ORDERS_PG_HOST / PORT / USERNAME / PASSWORD override the pgsql settings.
 * Uses two real PHP worker processes synchronized at a filesystem barrier.
 * Creates a random PostgreSQL schema per test, migrates it, and drops only it.
 * Does not run migrate:fresh or touch application tables in public.
 * The DB role needs permission to create/drop its test schemas.
 * Do not add RefreshDatabase or DatabaseTransactions to this file.
 * Missing ORDERS_PG_DATABASE means SKIPPED, not concurrency verified.
 * Requires proc_open (Symfony Process), pdo_pgsql, and the project's Tests\TestCase.
 * Not executed here; sequential retries alone do not prove concurrent correctness.
 */

use App\Auth\Domain\Services\AuthService;
use App\Customers\Models\Customer;
use App\Users\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

// Intentionally no RefreshDatabase/DatabaseTransactions: workers require committed fixtures.
// Each test migrates a new random PostgreSQL schema and drops only that schema afterward.

function ordersRaceVariantTable(): string
{
    foreach (['product_variants', 'variants'] as $table) {
        if (Schema::hasTable($table)) {
            return $table;
        }
    }

    throw new RuntimeException('Catalog variant table not found. Update ordersRaceVariantTable().');
}

function ordersRaceFixture(int $stock = 200, int $price = 1000, ?int $sale = null, string $status = 'published'): array
{
    $test = test();
    $slug = 'cart-test-'.Str::lower(Str::random(16));
    $sku = 'CART-'.Str::upper(Str::random(16));
    $test->withoutMiddleware();

    try {
        $test->postJson(route('catalog.products.store'), [
            'name' => 'Cart Test Bottle', 'slug' => $slug, 'status' => $status,
            'description' => 'Orders cart test fixture.',
        ])->assertSuccessful();
        $product = DB::table('products')->where('slug', $slug)->first();
        expect($product)->not->toBeNull();

        $payload = ['sku' => $sku, 'price' => $price, 'attributes' => ['color' => 'red', 'size' => '500ml']];
        if ($sale !== null) {
            $payload['sale_price'] = $sale;
        }
        $test->postJson(route('catalog.products.variants.store', ['product' => $product->id]), $payload)
            ->assertSuccessful();
        $variant = DB::table(ordersRaceVariantTable())->where('sku', $sku)->first();
        expect($variant)->not->toBeNull();

        if ($stock > 0) {
            DB::table('product_variants')->where('id', $variant->id)->update(['stock_quantity' => $stock]);
        }

        return ['product_id' => (int) $product->id, 'variant_id' => (int) $variant->id, 'sku' => $sku];
    } finally {
        $test->withMiddleware();
    }
}

function ordersRaceBalance(int $variantId): array
{
    foreach (['inventories', 'inventory', 'inventory_stocks', 'stock_levels', 'product_variants', 'variants'] as $table) {
        if (! Schema::hasTable($table)) {
            continue;
        }
        $key = in_array($table, ['product_variants', 'variants'], true) ? 'id' : 'variant_id';
        if (! Schema::hasColumn($table, $key)) {
            continue;
        }
        $row = DB::table($table)->where($key, $variantId)->first();
        if ($row === null) {
            continue;
        }
        $data = (array) $row;
        $on = collect(['on_hand', 'on_hand_stock', 'stock_on_hand', 'stock_quantity', 'quantity_on_hand'])
            ->first(fn ($name) => array_key_exists($name, $data));
        $reserved = collect(['reserved', 'reserved_stock', 'reserved_quantity', 'quantity_reserved'])
            ->first(fn ($name) => array_key_exists($name, $data));
        if ($on !== null && $reserved !== null) {
            return ['on_hand' => (int) $data[$on], 'reserved' => (int) $data[$reserved]];
        }
    }

    throw new RuntimeException('Inventory balance not found. Update ordersRaceBalance() to match Catalog.');
}

function ordersRaceLogin(User $user): void
{
    Auth::forgetGuards();
    test()->actingAs($user, 'customer')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
    ]);
}

function ordersRaceCustomer(): Customer
{
    $user = User::factory()->create(['role' => 'customer', 'is_active' => true]);

    return Customer::factory()->create(['user_id' => $user->id])->load('user');
}

function ordersRaceAmount(mixed $value, string $expected): void
{
    // Tests compare API money representations; production arithmetic must use minor units.
    expect(is_numeric($value))->toBeTrue();
    expect(number_format((float) $value, 2, '.', ''))->toBe($expected);
}

function ordersRacePayload(array $overrides = []): array
{
    return array_replace_recursive([
        'payment_method' => 'cod',
        'contact' => ['name' => 'Rahim Ahmed', 'phone' => '+8801712345678', 'email' => 'guest@example.test'],
        'delivery' => [
            'recipient_name' => 'Rahim Ahmed', 'phone' => '+8801712345678',
            'address_line1' => 'House 12, Road 3', 'city' => 'Dhaka',
            'district' => 'Dhaka', 'division' => 'Dhaka', 'country_code' => 'BD',
        ],
    ], $overrides);
}

function ordersRaceAdd(array $item, int $quantity = 2): void
{
    test()->postJson('/api/cart/items', ['variant_id' => $item['variant_id'], 'quantity' => $quantity])
        ->assertSuccessful();
}

function ordersRaceReviewed(array $payload): array
{
    $review = test()->postJson('/api/checkout/review', $payload)->assertOk();
    $token = $review->json('data.review_token');
    expect($token)->toBeString()->not->toBeEmpty();

    return [...$payload, 'review_token' => $token, 'idempotency_key' => 'test-'.Str::uuid()];
}

function ordersRaceNoOrderWrites(array $counts): void
{
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
}

function ordersRaceCounts(): array
{
    return collect(['orders', 'order_items', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
}

function ordersRacePlace(?Customer $customer = null, array $overrides = []): array
{
    if ($customer !== null) {
        ordersRaceLogin($customer->user);
    }
    $item = ordersRaceFixture();
    ordersRaceAdd($item);
    $payload = ordersRacePayload($overrides);
    if ($customer !== null) {
        unset($payload['contact']);
    }
    $request = ordersRaceReviewed($payload);
    $response = test()->postJson('/api/checkout', $request)->assertCreated();

    return [
        'id' => $response->json('data.order.id'),
        'number' => $response->json('data.order.order_number'),
        'token' => $response->json('data.guest_access_token'),
        'order' => $response->json('data.order'), 'request' => $request, 'item' => $item,
    ];
}

function ordersRaceStaff(array $permissions = ['orders.view', 'orders.process']): User
{
    $staff = User::factory()->create(['role' => 'staff', 'is_active' => true, 'permissions' => $permissions]);
    Auth::forgetGuards();
    test()->actingAs($staff, 'staff')->withSession([
        'auth_started_at' => now()->timestamp,
        'auth_fingerprint' => app(AuthService::class)->fingerprint($staff),
    ]);

    return $staff;
}

function ordersRaceAssertPrivate(mixed $data): void
{
    if (! is_array($data)) {
        return;
    }
    foreach ($data as $key => $value) {
        if (is_string($key)) {
            expect($key)->not->toBeIn([
                'password', 'remember_token', 'guest_token_hash', 'guest_access_token_hash',
                'checkout_hash', 'request_hash', 'provider_response', 'actor_id',
                'internal_notes', 'recorded_by', 'reconciled_by',
            ]);
        }
        ordersRaceAssertPrivate($value);
    }
}

function ordersRaceAdvance(array $placed, string $target): void
{
    if ($target === 'pending') {
        return;
    }
    foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
        test()->patchJson('/api/orders/'.$placed['id'].'/status', ['status' => $status])
            ->assertOk()->assertJsonPath('data.order.status', $status);
        if ($status === $target) {
            return;
        }
    }
    throw new InvalidArgumentException('Unknown target status: '.$target);
}

function ordersRaceDetails(array $placed): array
{
    return test()->getJson('/api/orders/'.$placed['id'])->assertOk()->json('data.order');
}

function ordersRaceShipping(array $placed): array
{
    $raw = DB::table('orders')->where('id', $placed['id'])->value('shipping_address');

    return is_array($raw) ? $raw : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
}

beforeEach(function (): void {
    $database = getenv('ORDERS_PG_DATABASE');
    if ($database === false || $database === '') {
        $this->markTestSkipped('Set ORDERS_PG_DATABASE to enable the isolated PostgreSQL worker checks.');
    }
    if (! extension_loaded('pdo_pgsql')) {
        $this->fail('ORDERS_PG_DATABASE is set, but PHP pdo_pgsql is unavailable.');
    }
    $this->raceSchema = 'orders_race_'.strtolower(Str::random(18));
    $connection = config('database.connections.pgsql');
    if (! is_array($connection)) {
        $this->fail('A pgsql connection must be configured in config/database.php.');
    }
    $connection['url'] = null;
    $connection['database'] = $database;
    foreach (['host', 'port', 'username', 'password'] as $key) {
        $override = getenv('ORDERS_PG_'.strtoupper($key));
        if ($override !== false) {
            $connection[$key] = $override;
        }
    }
    $connection['search_path'] = 'public';
    config(['database.default' => 'pgsql', 'database.connections.pgsql' => $connection,
        'session.driver' => 'array', 'cache.default' => 'array']);
    DB::purge('pgsql');
    DB::statement('CREATE SCHEMA "'.$this->raceSchema.'"');
    $connection['search_path'] = $this->raceSchema;
    config(['database.connections.pgsql' => $connection]);
    DB::purge('pgsql');
    expect(DB::connection()->transactionLevel())->toBe(0);
    expect(DB::selectOne('select current_schema() as schema_name')->schema_name)->toBe($this->raceSchema);
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    $this->raceConnection = $connection;
});

afterEach(function (): void {
    if (! isset($this->raceSchema)) {
        return;
    }
    // The name is generated above, never copied from user input.
    DB::purge('pgsql');
    DB::statement('DROP SCHEMA IF EXISTS "'.$this->raceSchema.'" CASCADE');
    DB::disconnect('pgsql');
});

function ordersRaceRequest(Customer $customer, array $item, int $quantity = 1): array
{
    ordersRaceLogin($customer->user);
    ordersRaceAdd($item, $quantity);
    $payload = ordersRacePayload();
    unset($payload['contact']);
    $request = ordersRaceReviewed($payload);

    return ['customer_id' => $customer->user_id, 'request' => $request, 'session' => session()->all()];
}

function ordersRaceWorkers(array $jobs): array
{
    $test = test();
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orders-workers-'.Str::uuid();
    if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create the worker barrier directory.');
    }
    $worker = $directory.DIRECTORY_SEPARATOR.'worker.php';
    $code = <<<'WORKER'
<?php
declare(strict_types=1);
$root = $argv[1];
require $root.'/vendor/autoload.php';
require_once $root.'/tests/TestCase.php';
$job = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
try {
    $case = new class('testWorker') extends \Tests\TestCase {
        public function testWorker(): void {}
        public function bootWorker(): void { $this->setUp(); }
    };
    $case->bootWorker();
    config([
        'database.default' => 'pgsql', 'database.connections.pgsql' => $job['connection'],
        'app.key' => $job['app_key'], 'session.driver' => 'array', 'cache.default' => 'array',
    ]);
    \Illuminate\Support\Facades\DB::purge('pgsql');
    if (\Illuminate\Support\Facades\DB::selectOne('select current_schema() as schema_name')->schema_name !== $job['connection']['search_path']) {
        throw new RuntimeException('Worker schema isolation check failed.');
    }
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $user = \App\Users\Models\User::findOrFail($job['customer_id']);
    $case->actingAs($user, 'customer')->withSession($job['session']);
    file_put_contents($job['ready'], 'ready');
    $deadline = microtime(true) + 25;
    while (! is_file($job['go'])) {
        if (microtime(true) >= $deadline) throw new RuntimeException('Worker barrier timed out.');
        usleep(10000);
    }
    $response = $case->postJson('/api/checkout', $job['request']);
    echo "\nORDERS_WORKER_RESULT=".json_encode([
        'status' => $response->status(), 'data' => $response->json('data'),
        'errors' => $response->json('errors'),
    ], JSON_THROW_ON_ERROR)."\n";
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
WORKER;
    file_put_contents($worker, $code);
    $processes = [];
    try {
        foreach ($jobs as $i => $job) {
            $payload = [...$job, 'connection' => $test->raceConnection,
                'app_key' => config('app.key'), 'ready' => $directory.DIRECTORY_SEPARATOR.'ready-'.$i,
                'go' => $directory.DIRECTORY_SEPARATOR.'go'];
            $process = new Process([PHP_BINARY, $worker, base_path()], base_path());
            $process->setTimeout(45);
            // Credentials and session material travel over stdin, never process arguments.
            $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 25;
        do {
            $ready = true;
            foreach ($processes as $i => $process) {
                if (! is_file($directory.DIRECTORY_SEPARATOR.'ready-'.$i)) {
                    $ready = false;
                    if (! $process->isRunning()) {
                        throw new RuntimeException('Worker could not initialize: '.$process->getErrorOutput());
                    }
                }
            }
            if ($ready) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Workers did not reach their shared start barrier.');
            }
            usleep(10000);
        } while (true);
        file_put_contents($directory.DIRECTORY_SEPARATOR.'go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Checkout worker failed: '.$process->getErrorOutput());
            }
            if (! preg_match('/^ORDERS_WORKER_RESULT=(.+)$/m', $process->getOutput(), $match)) {
                throw new RuntimeException('Checkout worker returned no structured result.');
            }
            $results[] = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($directory);
    }
}

it('allows only one of two customers to reserve the final available unit', function (): void {
    $item = ordersRaceFixture(stock: 1);
    $first = ordersRaceRequest(ordersRaceCustomer(), $item);
    $second = ordersRaceRequest(ordersRaceCustomer(), $item);
    $before = ordersRaceCounts();
    $balance = ordersRaceBalance($item['variant_id']);
    $results = ordersRaceWorkers([$first, $second]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([201, 422]);
    expect(DB::table('orders')->count())->toBe($before['orders'] + 1);
    expect(DB::table('order_items')->count())->toBe($before['order_items'] + 1);
    expect(DB::table('payments')->count())->toBe($before['payments'] + 1);
    expect(ordersRaceBalance($item['variant_id']))->toBe([
        'on_hand' => $balance['on_hand'], 'reserved' => $balance['reserved'] + 1,
    ]);
});

it('serializes simultaneous retries with the same key into one order and one reservation', function (): void {
    $item = ordersRaceFixture(stock: 10);
    $job = ordersRaceRequest(ordersRaceCustomer(), $item, 2);
    $before = ordersRaceCounts();
    $balance = ordersRaceBalance($item['variant_id']);
    $results = ordersRaceWorkers([$job, $job]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    expect($statuses)->toBe([200, 201]);
    expect($results[0]['data']['order']['id'])->toBe($results[1]['data']['order']['id']);
    $replays = array_column(array_column($results, 'data'), 'replayed');
    sort($replays);
    expect($replays)->toBe([false, true]);
    expect(DB::table('orders')->count())->toBe($before['orders'] + 1);
    expect(DB::table('order_items')->count())->toBe($before['order_items'] + 1);
    expect(DB::table('payments')->count())->toBe($before['payments'] + 1);
    expect(ordersRaceBalance($item['variant_id']))->toBe([
        'on_hand' => $balance['on_hand'], 'reserved' => $balance['reserved'] + 2,
    ]);
});
