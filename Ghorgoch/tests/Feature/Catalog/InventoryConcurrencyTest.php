<?php

namespace Tests\Feature\Catalog;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Customers\Models\Customer;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryConcurrencyTest extends TestCase
{
    public function test_two_postgresql_processes_cannot_reserve_the_last_unit(): void
    {
        $database = getenv('CATALOG_PG_DATABASE');
        if (! is_string($database) || $database === '') {
            $this->markTestSkipped('Set CATALOG_PG_DATABASE to run the PostgreSQL concurrency check in an isolated temporary schema.');
        }
        $schema = 'catalog_test_'.bin2hex(random_bytes(8));
        $connection = config('database.connections.pgsql');
        $connection['database'] = $database;
        $connection['url'] = null;
        $connection['search_path'] = $schema;
        config(['database.connections.catalog_concurrency' => $connection]);
        $original = DB::getDefaultConnection();
        DB::setDefaultConnection('catalog_concurrency');
        $workers = [];
        $created = false;
        try {
            DB::statement('CREATE SCHEMA '.$schema);
            $created = true;
            $this->assertSame(0, Artisan::call('migrate', ['--database' => 'catalog_concurrency', '--force' => true, '--no-interaction' => true]));
            $actor = User::factory()->create(['role' => 'admin']);
            $variant = ProductVariant::factory()->create([
                'product_id' => Product::factory()->create(['status' => 'published'])->id,
                'stock_quantity' => 1,
            ]);
            $orders = [];
            foreach ([1, 2] as $number) {
                $orders[] = Order::factory()->create([
                    'order_number' => 'RACE-'.$number, 'customer_id' => Customer::factory(),
                    'shipping_address' => '{}', 'subtotal' => 100, 'total_amount' => 100,
                ]);
            }
            foreach ($orders as $order) {
                $workers[] = $this->worker($connection, $actor->id, $variant->id, $order->id);
            }
            foreach ($workers as $worker) {
                $this->assertSame("ready\n", fgets($worker['pipes'][1]));
            }
            foreach ($workers as $worker) {
                fwrite($worker['pipes'][0], "go\n");
                fflush($worker['pipes'][0]);
                fclose($worker['pipes'][0]);
            }
            $results = [];
            foreach ($workers as $worker) {
                $results[] = trim(stream_get_contents($worker['pipes'][1]));
                $errors = stream_get_contents($worker['pipes'][2]);
                fclose($worker['pipes'][1]);
                fclose($worker['pipes'][2]);
                $this->assertSame(0, proc_close($worker['process']), $errors);
            }
            $workers = [];
            sort($results);

            $this->assertSame(['reserved', 'sold-out'], $results);
            $this->assertSame(1, $variant->fresh()->reserved_quantity);
            $this->assertSame(0, $variant->fresh()->availableStock());
            $this->assertSame(1, DB::table('stock_reservations')->count());
            $this->assertSame(1, DB::table('inventory_transactions')->count());
        } finally {
            foreach ($workers as $worker) {
                if (is_resource($worker['process'])) {
                    proc_terminate($worker['process']);
                }
                foreach ($worker['pipes'] as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                if (is_resource($worker['process'])) {
                    proc_close($worker['process']);
                }
            }
            if ($created && preg_match('/^catalog_test_[0-9a-f]{16}$/', $schema)) {
                DB::statement('DROP SCHEMA '.$schema.' CASCADE');
            }
            DB::setDefaultConnection($original);
            DB::purge('catalog_concurrency');
        }
    }

    private function worker(array $connection, int $actor, int $variant, int $order): array
    {
        $program = <<<'PHP'
$input = json_decode(fgets(STDIN), true, 512, JSON_THROW_ON_ERROR);
require $input['base'].'/vendor/autoload.php';
$app = require $input['base'].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'worker', 'database.connections.worker' => $input['connection']]);
Illuminate\Support\Facades\DB::setDefaultConnection('worker');
echo "ready\n";
fflush(STDOUT);
fgets(STDIN);
try {
    $actor = App\Users\Models\User::findOrFail($input['actor']);
    app(App\Catalog\Domain\Services\InventoryService::class)->reserve($actor, $input['variant'], $input['order'], 1);
    echo 'reserved';
} catch (Illuminate\Validation\ValidationException $exception) {
    echo 'sold-out';
}
PHP;
        $process = proc_open([PHP_BINARY, '-r', $program], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], $pipes, base_path());
        $this->assertIsResource($process);
        foreach ($pipes as $pipe) {
            stream_set_timeout($pipe, 30);
        }
        fwrite($pipes[0], json_encode([
            'base' => base_path(), 'connection' => $connection,
            'actor' => $actor, 'variant' => $variant, 'order' => $order,
        ], JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);

        return ['process' => $process, 'pipes' => $pipes];
    }
}
