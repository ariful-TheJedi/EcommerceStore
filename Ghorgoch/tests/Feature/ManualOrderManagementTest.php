<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ManualOrderManagementTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions = ['orders.view', 'orders.process']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);

        return $user;
    }

    private function variant(int $stock = 10): ProductVariant
    {
        return ProductVariant::factory()->create(['product_id' => Product::factory()->create(['status' => 'published'])->id,
            'stock_quantity' => $stock, 'price' => '100.00', 'cost_price' => '30.00']);
    }

    /** @return array<string, mixed> */
    private function payload(ProductVariant $variant): array
    {
        return ['idempotency_key' => 'manual-order-test', 'contact' => ['name' => 'Manual Buyer', 'phone' => '+8801712345678'],
            'delivery' => ['recipient_name' => 'Manual Buyer', 'phone' => '+8801712345678', 'address_line1' => 'Road 1', 'city' => 'Dhaka', 'district' => 'Dhaka', 'division' => 'Dhaka'],
            'shipping_fee' => '60.00', 'items' => [['variant_id' => $variant->id, 'quantity' => 2]]];
    }

    public function test_creation_and_deletion_require_staff_processing_permission(): void
    {
        $this->postJson('/api/orders', [])->assertUnauthorized();
        $this->deleteJson('/api/orders/1', ['reason' => 'Test'])->assertUnauthorized();
        $this->signIn(['orders.view']);
        $this->postJson('/api/orders', [])->assertForbidden();
        $this->deleteJson('/api/orders/1', ['reason' => 'Test'])->assertForbidden();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_manual_creation_uses_catalog_prices_snapshots_costs_and_reserves_stock_once(): void
    {
        $actor = $this->signIn();
        $variant = $this->variant();
        $body = $this->payload($variant);

        $response = $this->postJson('/api/orders', $body)->assertOk()->assertJsonPath('data.order.status', 'pending')
            ->assertJsonPath('data.order.subtotal', '200.00')->assertJsonPath('data.order.total_amount', '260.00')
            ->assertJsonPath('data.order.product_cost', '60.00')->assertJsonCount(1, 'data.order.items');
        $id = $response->json('data.order.id');
        $this->postJson('/api/orders', $body)->assertOk()->assertJsonPath('data.order.id', $id);

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseHas('orders', ['id' => $id, 'processed_by' => $actor->id, 'guest_name' => 'Manual Buyer']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 10, 'reserved_quantity' => 2]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $id, 'quantity' => 2, 'status' => 'reserved']);
        $this->assertDatabaseHas('order_history', ['order_id' => $id, 'action' => 'placed', 'actor_id' => $actor->id]);
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'confirmed'])->assertOk();
    }

    public function test_duplicate_submission_key_with_different_data_is_rejected(): void
    {
        $this->signIn();
        $variant = $this->variant();
        $body = $this->payload($variant);
        $this->postJson('/api/orders', $body)->assertOk();

        $this->postJson('/api/orders', [...$body, 'shipping_fee' => '100.00'])->assertConflict();

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'reserved_quantity' => 2]);
    }

    public function test_insufficient_stock_rolls_back_order_items_payment_and_inventory(): void
    {
        $this->signIn();
        $variant = $this->variant(1);

        $this->postJson('/api/orders', $this->payload($variant))->assertUnprocessable();

        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'reserved_quantity' => 0]);
    }

    public function test_manual_order_validation_rejects_price_overrides_duplicates_and_invalid_customer_data(): void
    {
        $this->signIn();
        $variant = $this->variant();
        $body = $this->payload($variant);
        $this->postJson('/api/orders', [])->assertUnprocessable()->assertJsonValidationErrors(['contact', 'delivery', 'items', 'shipping_fee', 'idempotency_key']);
        $this->postJson('/api/orders', [...$body, 'items' => [['variant_id' => $variant->id, 'quantity' => 2, 'unit_price' => '1.00']]])->assertUnprocessable()->assertJsonValidationErrors('items.0');
        $this->postJson('/api/orders', [...$body, 'items' => [$body['items'][0], $body['items'][0]]])->assertUnprocessable();
        $this->postJson('/api/orders', [...$body, 'contact' => ['name' => 'Buyer', 'phone' => 'invalid']])->assertUnprocessable()->assertJsonValidationErrors('contact.phone');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_delete_cancels_unshipped_order_releases_stock_and_preserves_history(): void
    {
        $this->signIn();
        $variant = $this->variant();
        $body = $this->payload($variant);
        $id = $this->postJson('/api/orders', $body)->assertOk()->json('data.order.id');

        $this->deleteJson('/api/orders/'.$id, ['reason' => 'Duplicate phone order'])->assertOk()->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('orders', ['id' => $id]);
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'cancelled']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $id, 'status' => 'released']);
        $this->assertDatabaseHas('order_history', ['order_id' => $id, 'action' => 'deleted', 'note' => 'Duplicate phone order']);
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'obligation_status' => 'closed']);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.pagination.total', 0);
        $this->getJson('/api/orders/'.$id)->assertNotFound();
        $this->postJson('/api/orders', $body)->assertConflict();
    }

    public function test_delivered_order_deletion_does_not_erase_income_or_profit(): void
    {
        $this->signIn();
        $order = Order::factory()->create(['status' => 'delivered', 'product_cost' => '60.00', 'expense_amount' => '20.00']);
        $before = $this->getJson('/api/orders/report')->assertOk()->json('data');

        $this->deleteJson('/api/orders/'.$order->id, ['reason' => 'Remove from active lists'])->assertOk();

        $this->getJson('/api/orders/report')->assertOk()->assertExactJson(['message' => 'Success.', 'data' => $before]);
        $this->assertSoftDeleted('orders', ['id' => $order->id]);
    }

    public function test_in_transit_orders_cannot_be_deleted_and_reason_is_required(): void
    {
        $this->signIn();
        $order = Order::factory()->create(['status' => 'shipped']);
        $this->deleteJson('/api/orders/'.$order->id, [])->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->deleteJson('/api/orders/'.$order->id, ['reason' => 'Delete while in transit'])->assertConflict();

        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
        $this->assertSame(0, DB::table('order_history')->count());
    }

    public function test_inactive_products_are_rejected_without_order_writes(): void
    {
        $this->signIn();
        $variant = $this->variant();
        $variant->is_active = false;
        $variant->save();

        $this->postJson('/api/orders', $this->payload($variant))->assertUnprocessable()->assertJsonValidationErrors('items');

        $this->assertDatabaseCount('orders', 0);
    }
}
