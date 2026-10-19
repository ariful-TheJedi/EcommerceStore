<?php

namespace Tests\Feature\Catalog;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Customers\Models\Customer;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function actor(array $permissions = ['catalog.view', 'inventory.adjust', 'orders.process']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession([
            'auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user),
        ]);

        return $user;
    }

    private function variant(int $stock = 2): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id, 'stock_quantity' => $stock,
        ]);
    }

    private function order(string $number): Order
    {
        return Order::factory()->create([
            'order_number' => $number, 'customer_id' => Customer::factory(),
            'shipping_address' => '{}', 'subtotal' => 100, 'total_amount' => 100,
        ]);
    }

    public function test_manual_adjustments_require_reason_permission_and_an_idempotency_key(): void
    {
        $variant = $this->variant();
        $actor = $this->actor();
        $url = '/api/inventory/'.$variant->id.'/adjust';
        $payload = ['quantity_change' => 3, 'reason' => 'Supplier delivery', 'operation_key' => 'delivery-001'];

        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.variant.available_stock', 5);
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.variant.stock_quantity', 5);
        $this->postJson($url, array_replace($payload, ['quantity_change' => 4]))->assertConflict();
        $this->postJson($url, ['quantity_change' => 1])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'operation_key']);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->assertDatabaseHas('inventory_transactions', ['actor_id' => $actor->id, 'quantity_change' => 3, 'note' => 'Supplier delivery']);
    }

    public function test_stock_cannot_be_reduced_below_reservations(): void
    {
        $variant = $this->variant(5);
        $order = $this->order('ADJUST-RESERVED');
        $this->actor();
        $this->postJson('/api/inventory/'.$variant->id.'/reserve', ['order_id' => $order->id, 'quantity' => 4])->assertOk();

        $this->postJson('/api/inventory/'.$variant->id.'/adjust', [
            'quantity_change' => -2, 'reason' => 'Correction', 'operation_key' => 'correction-1',
        ])->assertUnprocessable();

        $this->assertSame(5, $variant->fresh()->stock_quantity);
        $this->assertSame(4, $variant->fresh()->reserved_quantity);
    }

    public function test_competing_orders_cannot_reserve_the_last_unit(): void
    {
        $variant = $this->variant(1);
        $one = $this->order('LAST-ONE');
        $two = $this->order('LAST-TWO');
        $this->actor();
        $url = '/api/inventory/'.$variant->id.'/reserve';

        $this->postJson($url, ['order_id' => $one->id, 'quantity' => 1])->assertOk();
        $this->postJson($url, ['order_id' => $two->id, 'quantity' => 1])->assertUnprocessable();

        $this->assertSame(0, $variant->fresh()->availableStock());
        $this->assertDatabaseCount('stock_reservations', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
    }

    public function test_reservation_and_deduction_are_idempotent_and_record_both_quantities(): void
    {
        $variant = $this->variant(5);
        $order = $this->order('DEDUCT');
        $this->actor();
        $base = '/api/inventory/'.$variant->id;
        $payload = ['order_id' => $order->id, 'quantity' => 2];

        $this->postJson($base.'/reserve', $payload)->assertOk();
        $this->postJson($base.'/reserve', $payload)->assertOk();
        $this->postJson($base.'/deduct', ['order_id' => $order->id])->assertOk()->assertJsonPath('data.reservation.status', 'deducted');
        $this->postJson($base.'/deduct', ['order_id' => $order->id])->assertOk();
        $this->postJson($base.'/release', ['order_id' => $order->id])->assertConflict();

        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseHas('inventory_transactions', ['order_id' => $order->id, 'transaction_type' => 'deduct', 'quantity_change' => -2, 'reserved_change' => -2]);
    }

    public function test_release_is_idempotent_and_works_after_product_archive(): void
    {
        $variant = $this->variant(3);
        $order = $this->order('RELEASE');
        $this->actor();
        $base = '/api/inventory/'.$variant->id;
        $this->postJson($base.'/reserve', ['order_id' => $order->id, 'quantity' => 2])->assertOk();
        $variant->product->update(['status' => 'archived']);

        $this->postJson($base.'/release', ['order_id' => $order->id])->assertOk();
        $this->postJson($base.'/release', ['order_id' => $order->id])->assertOk();

        $this->assertSame(3, $variant->fresh()->availableStock());
        $this->assertSame(3, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 2);
    }

    public function test_archived_or_inactive_items_cannot_be_reserved(): void
    {
        $variant = $this->variant(3);
        $order = $this->order('UNPURCHASABLE');
        $this->actor();
        $url = '/api/inventory/'.$variant->id.'/reserve';
        $variant->update(['is_active' => false]);

        $this->postJson($url, ['order_id' => $order->id, 'quantity' => 1])->assertUnprocessable();
        $variant->update(['is_active' => true]);
        $variant->product->update(['status' => 'archived']);
        $this->postJson($url, ['order_id' => $order->id, 'quantity' => 1])->assertUnprocessable();

        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_backorders_are_opt_in_and_cannot_be_deducted_until_stock_arrives(): void
    {
        $variant = $this->variant(0);
        $order = $this->order('BACKORDER');
        $this->actor();
        $url = '/api/inventory/'.$variant->id;

        $this->postJson($url.'/reserve', ['order_id' => $order->id, 'quantity' => 1])->assertUnprocessable();
        $variant->allow_backorders = true;
        $variant->save();
        $this->postJson($url.'/reserve', ['order_id' => $order->id, 'quantity' => 1])->assertOk();
        $this->postJson($url.'/deduct', ['order_id' => $order->id])->assertUnprocessable();
        $this->postJson($url.'/adjust', ['quantity_change' => 1, 'reason' => 'Receive backorder', 'operation_key' => 'backorder-1'])->assertOk();
        $this->postJson($url.'/deduct', ['order_id' => $order->id])->assertOk();

        $this->assertSame(0, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
    }

    public function test_inventory_permissions_are_separate_from_catalog_management(): void
    {
        $variant = $this->variant();
        $this->actor(['catalog.manage']);

        $this->postJson('/api/inventory/'.$variant->id.'/adjust', [
            'quantity_change' => 1, 'reason' => 'Denied', 'operation_key' => 'denied',
        ])->assertForbidden();
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_low_stock_and_out_of_stock_filters_use_available_quantities(): void
    {
        $one = $this->variant(3);
        $two = $this->variant(0);
        $this->actor();

        $this->getJson('/api/inventory?availability=low_stock')->assertOk()->assertJsonPath('data.variants.0.id', $one->id)->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/inventory?availability=out_of_stock')->assertOk()->assertJsonPath('data.variants.0.id', $two->id)->assertJsonPath('data.pagination.total', 1);
    }

    public function test_manual_adjustment_can_reference_an_order_and_replay_checks_that_reference(): void
    {
        $variant = $this->variant();
        $order = $this->order('MANUAL-ORDER');
        $this->actor();
        $payload = ['quantity_change' => 1, 'reason' => 'Returned unopened item', 'operation_key' => 'return-order', 'order_id' => $order->id];
        $url = '/api/inventory/'.$variant->id.'/adjust';

        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, array_replace($payload, ['order_id' => null]))->assertConflict();

        $this->assertDatabaseHas('inventory_transactions', ['variant_id' => $variant->id, 'order_id' => $order->id, 'operation_key' => 'return-order']);
        $this->assertSame(3, $variant->fresh()->stock_quantity);
    }
}
