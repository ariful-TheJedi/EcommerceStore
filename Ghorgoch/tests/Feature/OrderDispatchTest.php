<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderDispatchTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @return array{0: Order, 1: ProductVariant} */
    private function packedOrder(int $stock = 8): array
    {
        $actor = User::factory()->create(['role' => 'staff', 'permissions' => ['orders.view', 'orders.process']]);
        $this->actingAs($actor, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($actor)]);
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['status' => 'published'])->id, 'stock_quantity' => $stock]);
        $order = Order::factory()->create(['status' => 'processing']);
        OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $variant->product_id, 'quantity' => 2]);

        return [$order, $variant];
    }

    private function oldDeduction(Order $order, ProductVariant $variant, int $quantity): void
    {
        DB::table('inventory_transactions')->insert(['order_id' => $order->id, 'variant_id' => $variant->id,
            'transaction_type' => 'stock_out', 'quantity_change' => -$quantity, 'reserved_change' => 0,
            'operation_key' => 'legacy-deduction-'.$order->id, 'note' => 'Legacy order deduction', 'created_at' => now()]);
    }

    public function test_legacy_order_dispatch_reconstructs_reservation_without_deducting_stock_twice(): void
    {
        [$order, $variant] = $this->packedOrder();
        $this->oldDeduction($order, $variant, 2);

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk()
            ->assertJsonPath('data.order.status', 'shipped')->assertJsonPath('data.order.delivery_status', 'in_transit');
        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => 2, 'status' => 'deducted']);
        $this->assertSame(1, DB::table('inventory_transactions')->where('order_id', $order->id)->count());
        $this->assertSame(1, DB::table('order_history')->where('order_id', $order->id)->where('action', 'status')->count());
    }

    public function test_legacy_order_without_stock_history_reserves_and_deducts_once(): void
    {
        [$order, $variant] = $this->packedOrder(10);

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'deducted']);
        $this->assertDatabaseHas('inventory_transactions', ['order_id' => $order->id, 'transaction_type' => 'deduct', 'quantity_change' => -2]);
    }

    public function test_legacy_dispatch_fails_atomically_when_stock_is_insufficient(): void
    {
        [$order, $variant] = $this->packedOrder(1);

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('quantity');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 1, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('order_history', 0);
    }

    public function test_inconsistent_legacy_stock_history_returns_validation_error_without_changes(): void
    {
        [$order, $variant] = $this->packedOrder();
        $this->oldDeduction($order, $variant, 1);

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('inventory');

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8]);
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_missing_modern_reservation_is_reported_as_inventory_error_instead_of_404(): void
    {
        [$order, $variant] = $this->packedOrder();
        $order->checkout_key = str_repeat('a', 64);
        $order->save();

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertUnprocessable()->assertJsonValidationErrors('inventory');

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'processing']);
    }

    public function test_reconciled_legacy_order_can_be_returned_to_stock_once(): void
    {
        [$order, $variant] = $this->packedOrder();
        $this->oldDeduction($order, $variant, 2);
        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'shipped'])->assertOk();

        $body = ['status' => 'returned', 'reason' => 'Customer refused delivery', 'restock' => true];
        $this->patchJson('/api/orders/'.$order->id.'/status', $body)->assertOk();
        $this->patchJson('/api/orders/'.$order->id.'/status', $body)->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertSame(1, DB::table('inventory_transactions')->where('order_id', $order->id)->where('transaction_type', 'return')->count());
    }
}
