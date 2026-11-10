<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BulkOrderDeletionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions = ['orders.view', 'orders.process']): void
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
    }

    public function test_bulk_deletion_requires_staff_processing_permission(): void
    {
        $this->postJson('/api/orders/bulk-delete', [])->assertUnauthorized();
        $this->signIn(['orders.view']);
        $this->postJson('/api/orders/bulk-delete', [])->assertForbidden();
    }

    public function test_deletion_list_shows_every_order_status_with_correct_pagination(): void
    {
        $this->signIn();
        foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'returned', 'cancelled'] as $status) {
            Order::factory()->create(['status' => $status]);
        }
        $returned = Order::where('status', 'returned')->firstOrFail();
        OrderItem::factory()->create(['order_id' => $returned->id, 'product_name' => 'Ceramic cup', 'quantity' => 3]);

        $this->getJson('/api/orders?deletion_list=1&per_page=1&page=2')->assertOk()
            ->assertJsonPath('data.pagination.total', 7)->assertJsonPath('data.pagination.last_page', 7)
            ->assertJsonPath('data.orders.0.status', 'returned')
            ->assertJsonPath('data.orders.0.items.0.product_name', 'Ceramic cup')
            ->assertJsonPath('data.orders.0.items.0.quantity', 3)
            ->assertJsonPath('data.orders.0.can_process', true)
            ->assertJsonMissingPath('data.orders.0.product_cost');
        $this->getJson('/api/orders?deletion_list=1&status=delivered')->assertOk()
            ->assertJsonPath('data.pagination.total', 1)->assertJsonPath('data.orders.0.status', 'delivered');
        $this->getJson('/api/orders?deletion_list=1&status=pending')->assertOk()->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.orders.0.status', 'pending');
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.pagination.total', 7);
    }

    public function test_selected_orders_are_deleted_while_unselected_orders_and_financial_totals_remain(): void
    {
        $this->signIn();
        $first = Order::factory()->create(['status' => 'delivered', 'product_cost' => '50.00']);
        $second = Order::factory()->create(['status' => 'returned', 'product_cost' => '40.00']);
        $unselected = Order::factory()->create(['status' => 'cancelled']);
        $before = $this->getJson('/api/orders/report')->assertOk()->json('data');

        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [$first->id, $second->id], 'reason' => 'Completed orders cleanup'])->assertOk()->assertJsonPath('data.deleted_count', 2);

        $this->assertSoftDeleted('orders', ['id' => $first->id]);
        $this->assertSoftDeleted('orders', ['id' => $second->id]);
        $this->assertNotSoftDeleted('orders', ['id' => $unselected->id]);
        $this->assertDatabaseHas('order_history', ['order_id' => $first->id, 'action' => 'deleted', 'note' => 'Completed orders cleanup']);
        $this->assertDatabaseHas('order_history', ['order_id' => $second->id, 'action' => 'deleted']);
        $this->getJson('/api/orders/report')->assertOk()->assertExactJson(['message' => 'Success.', 'data' => $before]);
        $this->getJson('/api/orders')->assertOk()->assertJsonPath('data.pagination.total', 1);
    }

    public function test_orders_in_every_status_can_be_deleted_together(): void
    {
        $this->signIn();
        $orders = collect(['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'returned', 'cancelled'])
            ->map(fn (string $status): Order => Order::factory()->create(['status' => $status]));

        $this->postJson('/api/orders/bulk-delete', ['order_ids' => $orders->pluck('id')->all(), 'reason' => 'Remove selected orders'])
            ->assertOk()->assertJsonPath('data.deleted_count', 7);

        foreach ($orders as $order) {
            $this->assertSoftDeleted('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'action' => 'deleted']);
        }
        $this->getJson('/api/orders?deletion_list=1')->assertOk()->assertJsonPath('data.pagination.total', 0);
    }

    public function test_each_order_status_can_be_deleted_individually(): void
    {
        $this->signIn();
        foreach (['pending', 'confirmed', 'processing', 'shipped', 'delivered', 'returned', 'cancelled'] as $status) {
            $order = Order::factory()->create(['status' => $status]);
            $this->deleteJson('/api/orders/'.$order->id, ['reason' => 'Remove order'])->assertOk()->assertJsonPath('data.deleted', true);
            $this->assertSoftDeleted('orders', ['id' => $order->id]);
            $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'action' => 'deleted']);
        }
    }

    public function test_deleting_unshipped_orders_releases_stock_and_closes_payment_obligations(): void
    {
        $this->signIn();
        foreach (['pending', 'confirmed', 'processing'] as $status) {
            $variant = ProductVariant::factory()->create(['stock_quantity' => 10, 'reserved_quantity' => 2]);
            $order = Order::factory()->create(['status' => $status]);
            OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => 2]);
            DB::table('stock_reservations')->insert(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => 2, 'status' => 'reserved', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'cod', 'amount' => $order->total_amount, 'currency' => 'BDT', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

            $this->deleteJson('/api/orders/'.$order->id, ['reason' => 'Duplicate order'])->assertOk();

            $this->assertSoftDeleted('orders', ['id' => $order->id, 'status' => 'cancelled']);
            $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
            $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'released']);
            $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending', 'obligation_status' => 'closed']);
        }
    }

    public function test_deleting_shipped_orders_preserves_dispatch_and_payment_history(): void
    {
        $this->signIn();
        $variant = ProductVariant::factory()->create(['stock_quantity' => 8, 'reserved_quantity' => 0]);
        $order = Order::factory()->create(['status' => 'shipped', 'delivery_status' => 'in_transit']);
        OrderItem::factory()->create(['order_id' => $order->id, 'product_id' => $variant->product_id, 'variant_id' => $variant->id, 'quantity' => 2]);
        DB::table('stock_reservations')->insert(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => 2, 'status' => 'deducted', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'cod', 'amount' => $order->total_amount, 'currency' => 'BDT', 'status' => 'pending', 'obligation_status' => 'open', 'created_at' => now(), 'updated_at' => now()]);

        $this->deleteJson('/api/orders/'.$order->id, ['reason' => 'Remove from order list'])->assertOk();

        $this->assertSoftDeleted('orders', ['id' => $order->id, 'status' => 'shipped', 'delivery_status' => 'in_transit']);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8, 'reserved_quantity' => 0]);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $order->id, 'status' => 'deducted']);
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'status' => 'pending', 'obligation_status' => 'open']);
        $this->assertDatabaseHas('order_items', ['order_id' => $order->id, 'quantity' => 2]);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_batch_rolls_back_when_an_order_reservation_cannot_be_released(): void
    {
        $this->signIn();
        $first = Order::factory()->create(['status' => 'cancelled']);
        $pending = Order::factory()->create(['status' => 'pending']);
        OrderItem::factory()->create(['order_id' => $pending->id]);

        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [$first->id, $pending->id], 'reason' => 'Cleanup'])->assertNotFound();

        $this->assertNotSoftDeleted('orders', ['id' => $first->id]);
        $this->assertNotSoftDeleted('orders', ['id' => $pending->id]);
        $this->assertDatabaseCount('order_history', 0);
    }

    public function test_batch_validates_ids_duplicates_and_reason_before_deleting(): void
    {
        $this->signIn();
        $order = Order::factory()->create(['status' => 'cancelled']);
        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [], 'reason' => 'Cleanup'])->assertUnprocessable()->assertJsonValidationErrors('order_ids');
        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [$order->id, $order->id], 'reason' => 'Cleanup'])->assertUnprocessable();
        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [$order->id]])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/orders/bulk-delete', ['order_ids' => [$order->id, 999999], 'reason' => 'Cleanup'])->assertUnprocessable();
        $this->assertNotSoftDeleted('orders', ['id' => $order->id]);
        $this->assertDatabaseCount('order_history', 0);
    }
}
