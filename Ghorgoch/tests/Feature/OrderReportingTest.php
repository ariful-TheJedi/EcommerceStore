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

class OrderReportingTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param list<string> $permissions */
    private function signIn(array $permissions = ['orders.view', 'orders.process']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->actingAs($user, 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);

        return $user;
    }

    public function test_report_requires_staff_and_view_permission(): void
    {
        $this->getJson('/api/orders/report')->assertUnauthorized();
        $this->signIn(['orders.process']);
        $this->getJson('/api/orders/report')->assertForbidden();
        $this->signIn(['orders.view']);
        $this->getJson('/api/orders/report')->assertOk()->assertJsonPath('data.total_orders', 0);
    }

    public function test_report_counts_units_and_calculates_profit_without_multiplying_order_totals(): void
    {
        $this->signIn();
        $delivered = Order::factory()->create(['status' => 'delivered', 'product_cost' => '80.15', 'expense_amount' => '20.25', 'total_amount' => '260.50', 'tax_amount' => '10.00']);
        OrderItem::factory()->count(2)->create(['order_id' => $delivered->id, 'quantity' => 3]);
        $returned = Order::factory()->create(['status' => 'returned', 'product_cost' => '90.10', 'expense_amount' => '30.20', 'return_restocked' => true]);
        OrderItem::factory()->create(['order_id' => $returned->id, 'quantity' => 2]);
        $lost = Order::factory()->create(['status' => 'returned', 'product_cost' => '40.05', 'expense_amount' => '10.15']);
        OrderItem::factory()->create(['order_id' => $lost->id, 'quantity' => 1]);
        Order::factory()->create(['status' => 'pending', 'expense_amount' => '5.00']);

        $this->getJson('/api/orders/report')->assertOk()->assertJsonPath('data.total_orders', 4)
            ->assertJsonPath('data.counts.pending', 1)->assertJsonPath('data.counts.returned', 2)
            ->assertJsonPath('data.currencies.0.delivered_items', 6)->assertJsonPath('data.currencies.0.returned_items', 3)
            ->assertJsonPath('data.currencies.0.income', '250.50')->assertJsonPath('data.currencies.0.product_expenses', '120.20')
            ->assertJsonPath('data.currencies.0.operating_expenses', '65.60')->assertJsonPath('data.currencies.0.expenses', '185.80')
            ->assertJsonPath('data.currencies.0.profit', '64.70');
    }

    public function test_missing_costs_block_profit_and_currencies_are_separate(): void
    {
        $this->signIn();
        Order::factory()->create(['status' => 'delivered', 'product_cost' => null]);
        Order::factory()->create(['status' => 'delivered', 'currency' => 'USD', 'product_cost' => '300.00', 'total_amount' => '260.00']);

        $this->getJson('/api/orders/report')->assertOk()->assertJsonCount(2, 'data.currencies')
            ->assertJsonPath('data.currencies.0.currency', 'BDT')->assertJsonPath('data.currencies.0.profit', null)
            ->assertJsonPath('data.currencies.0.missing_cost_orders', 1)
            ->assertJsonPath('data.currencies.1.currency', 'USD')->assertJsonPath('data.currencies.1.profit', '-40.00');
    }

    public function test_product_search_and_inclusive_single_date_filter_apply_to_whole_report(): void
    {
        $this->signIn();
        $early = Order::factory()->create(['created_at' => '2026-11-01 00:00:00']);
        $late = Order::factory()->create(['created_at' => '2026-11-01 23:59:59']);
        $outside = Order::factory()->create(['created_at' => '2026-11-02 00:00:00']);
        foreach ([$early, $late, $outside] as $order) {
            OrderItem::factory()->create(['order_id' => $order->id, 'product_name' => 'Blue bottle', 'sku' => 'BOTTLE-1']);
        }
        Order::factory()->create(['created_at' => '2026-11-01 12:00:00']);
        $query = '?from=2026-11-01&to=2026-11-01&search=Blue&per_page=1';

        $this->getJson('/api/orders'.$query)->assertOk()->assertJsonPath('data.pagination.total', 2)
            ->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.item_count', 2);
        $this->getJson('/api/orders/report'.$query)->assertOk()->assertJsonPath('data.total_orders', 2);
        $this->getJson('/api/orders?search=BOTTLE-1')->assertOk()->assertJsonPath('data.pagination.total', 3);
        $this->getJson('/api/orders/report?from=2026-11-02&to=2026-11-01')->assertUnprocessable()->assertJsonValidationErrors('to');
    }

    public function test_cost_updates_require_permission_validate_and_create_audit_history(): void
    {
        $order = Order::factory()->create();
        $body = ['product_cost' => '75.50', 'expense_amount' => '12.25', 'note' => 'Purchase and courier invoice'];
        $this->signIn(['orders.view']);
        $this->patchJson('/api/orders/'.$order->id.'/finances', $body)->assertForbidden();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'product_cost' => null]);
        $actor = $this->signIn();
        $this->patchJson('/api/orders/'.$order->id.'/finances', [...$body, 'expense_amount' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('expense_amount');
        $this->patchJson('/api/orders/'.$order->id.'/finances', [...$body, 'product_cost' => '1.123'])->assertUnprocessable()->assertJsonValidationErrors('product_cost');
        $this->patchJson('/api/orders/'.$order->id.'/finances', [...$body, 'actor_id' => 3])->assertUnprocessable()->assertJsonValidationErrors('actor_id');

        $this->patchJson('/api/orders/'.$order->id.'/finances', $body)->assertOk()
            ->assertJsonPath('data.order.product_cost', '75.50')->assertJsonPath('data.order.expense_amount', '12.25');

        $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'actor_id' => $actor->id, 'action' => 'finances', 'note' => $body['note']]);
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'product_cost' => '75.50', 'expense_amount' => '12.25']);
    }

    public function test_return_restocks_dispatched_units_once_and_flags_collected_payment_for_refund(): void
    {
        $this->freezeTime();
        $this->signIn();
        $variant = ProductVariant::factory()->create(['stock_quantity' => 8]);
        $order = Order::factory()->create(['status' => 'delivered', 'delivery_status' => 'delivered']);
        OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $variant->product_id]);
        DB::table('stock_reservations')->insert(['order_id' => $order->id, 'variant_id' => $variant->id, 'quantity' => 2, 'status' => 'deducted', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('payments')->insert(['order_id' => $order->id, 'provider' => 'cod', 'amount' => '260.00', 'currency' => 'BDT', 'status' => 'successful', 'created_at' => now(), 'updated_at' => now()]);
        $body = ['status' => 'returned', 'reason' => 'Customer returned unopened items', 'restock' => true];

        $this->patchJson('/api/orders/'.$order->id.'/status', $body)->assertOk()->assertJsonPath('data.order.status', 'returned')->assertJsonPath('data.order.return_restocked', true);
        $this->patchJson('/api/orders/'.$order->id.'/status', $body)->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 10]);
        $this->assertSame(1, DB::table('inventory_transactions')->where('order_id', $order->id)->where('transaction_type', 'return')->count());
        $this->assertSame(1, DB::table('order_history')->where('order_id', $order->id)->where('action', 'status')->count());
        $this->assertDatabaseHas('payments', ['order_id' => $order->id, 'obligation_status' => 'refund_required']);
        $this->assertNotNull($order->fresh()->returned_at);
    }

    public function test_damaged_return_leaves_stock_unchanged_and_reports_a_loss(): void
    {
        $this->signIn();
        $variant = ProductVariant::factory()->create(['stock_quantity' => 8]);
        $order = Order::factory()->create(['status' => 'shipped', 'delivery_status' => 'in_transit', 'product_cost' => '90.00', 'expense_amount' => '25.00']);
        OrderItem::factory()->create(['order_id' => $order->id, 'variant_id' => $variant->id, 'product_id' => $variant->product_id]);

        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'returned', 'reason' => 'Damaged in transit', 'restock' => false])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 8]);
        $this->getJson('/api/orders/report')->assertOk()->assertJsonPath('data.currencies.0.income', '0.00')->assertJsonPath('data.currencies.0.profit', '-115.00');
    }

    public function test_returns_require_reason_restock_choice_and_a_dispatched_order(): void
    {
        $this->signIn();
        $order = Order::factory()->create();
        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'returned'])->assertUnprocessable()->assertJsonValidationErrors(['reason', 'restock']);
        $this->patchJson('/api/orders/'.$order->id.'/status', ['status' => 'returned', 'reason' => 'Invalid return', 'restock' => false])->assertConflict();
        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertSame(0, DB::table('order_history')->count());
    }

    public function test_checkout_snapshots_product_cost_and_does_not_expose_it_to_guests(): void
    {
        $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['status' => 'published'])->id, 'stock_quantity' => 10, 'cost_price' => '35.15', 'price' => '100.00']);
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertSuccessful();
        $payload = ['payment_method' => 'cod', 'contact' => ['name' => 'Buyer', 'phone' => '+8801712345678'],
            'delivery' => ['recipient_name' => 'Buyer', 'phone' => '+8801712345678', 'address_line1' => 'Test Road', 'city' => 'Dhaka', 'district' => 'Dhaka', 'division' => 'Dhaka', 'country_code' => 'BD']];
        $token = $this->postJson('/api/checkout/review', $payload)->assertOk()->json('data.review_token');

        $response = $this->postJson('/api/checkout', [...$payload, 'review_token' => $token, 'idempotency_key' => 'cost-snapshot-test'])->assertCreated()->assertJsonMissingPath('data.order.product_cost');

        $id = $response->json('data.order.id');
        $variant->cost_price = '80.00';
        $variant->save();
        $this->assertDatabaseHas('orders', ['id' => $id, 'product_cost' => '70.30']);
    }
}
