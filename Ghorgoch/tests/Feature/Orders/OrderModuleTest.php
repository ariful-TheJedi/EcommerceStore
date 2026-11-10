<?php

namespace Tests\Feature\Orders;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Customers\Models\Address;
use App\Customers\Models\Customer;
use App\Orders\Models\Cart;
use App\Orders\Models\Order;
use App\Payments\Domain\Services\CodOrderPayments;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class OrderModuleTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(array $fields = []): ProductVariant
    {
        return ProductVariant::factory()->create([
            'product_id' => Product::factory()->create(['status' => 'published'])->id,
            'price' => '100.25', 'stock_quantity' => 20, ...$fields,
        ]);
    }

    private function delivery(array $fields = []): array
    {
        return ['recipient_name' => 'Rahim', 'phone' => '+8801712345678', 'address_line1' => 'House 12',
            'city' => 'Dhaka', 'district' => 'Dhaka', 'division' => 'Dhaka', 'country_code' => 'BD', 'postal_code' => '1216', ...$fields];
    }

    private function payload(array $fields = []): array
    {
        return ['contact' => ['name' => 'Guest', 'email' => 'guest@example.test', 'phone' => '+8801712345678'],
            'delivery' => $this->delivery(), 'payment_method' => 'cod', ...$fields];
    }

    private function login(User $user, string $guard = 'customer'): void
    {
        Auth::forgetGuards();
        $this->actingAs($user, $guard)->withSession(['auth_started_at' => now()->timestamp,
            'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['user_id' => User::factory()->create(['role' => 'customer', 'password' => 'CorrectPassword@123'])->id])->load('user');
    }

    private function staff(array $permissions = ['orders.view', 'orders.process']): User
    {
        $user = User::factory()->create(['role' => 'staff', 'permissions' => $permissions]);
        $this->login($user, 'staff');

        return $user;
    }

    private function place(?ProductVariant $variant = null, int $quantity = 2): array
    {
        $variant ??= $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => $quantity])->assertOk();
        $data = $this->payload();
        $review = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');

        return $this->postJson('/api/checkout', [...$data, 'review_token' => $review, 'idempotency_key' => 'checkout-test'])
            ->assertCreated()->json('data');
    }

    public function test_guest_cart_merges_duplicate_lines_recalculates_prices_and_does_not_reserve_stock(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk()
            ->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.quantity', 3)
            ->assertJsonPath('data.cart.subtotal', '300.75');
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
        $variant->price = '110.50';
        $variant->save();
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.subtotal', '331.50');
        $this->patchJson('/api/cart/items/'.$variant->id, ['quantity' => 1])->assertOk()->assertJsonPath('data.cart.subtotal', '110.50');
        $this->deleteJson('/api/cart/items/'.$variant->id)->assertOk()->assertJsonCount(0, 'data.cart.items');
        $this->assertDatabaseCount('orders', 0);
    }

    #[TestWith([0])]
    #[TestWith([-1])]
    #[TestWith([1.5])]
    #[TestWith([101])]
    public function test_cart_rejects_invalid_quantities(mixed $quantity): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => $quantity])->assertUnprocessable()->assertJsonValidationErrors('quantity');
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_unavailable_items_are_rejected_and_existing_cart_is_revalidated(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $variant->is_active = false;
        $variant->save();
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.can_checkout', false);
        $this->postJson('/api/checkout/review', $this->payload())->assertUnprocessable();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertUnprocessable();
        $this->assertDatabaseCount('stock_reservations', 0);
    }

    public function test_guest_checkout_is_atomic_snapshots_items_and_creates_an_unpaid_cod_record(): void
    {
        $variant = $this->variant();
        $result = $this->place($variant);
        $id = $result['order']['id'];
        $this->assertSame('260.50', $result['order']['total_amount']);
        $this->assertSame('unpaid', $result['order']['payment_status']);
        $this->assertSame(64, strlen($result['guest_access_token']));
        $this->assertDatabaseHas('orders', ['id' => $id, 'customer_id' => null, 'subtotal' => 200.50, 'shipping_fee' => 60]);
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'provider' => 'cod', 'status' => 'pending', 'obligation_status' => 'open']);
        $this->assertDatabaseHas('order_items', ['order_id' => $id, 'sku' => $variant->sku, 'quantity' => 2]);
        $this->assertSame(2, $variant->fresh()->reserved_quantity);
        $this->assertSame(20, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_price_tampering_is_rejected_and_price_changes_require_another_review(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1, 'price' => 1])->assertUnprocessable();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $data = $this->payload();
        $token = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');
        $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'test', 'total_amount' => 1])->assertUnprocessable();
        $variant->price = '120.00';
        $variant->save();
        $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'test'])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('orders', 0);
        $newToken = $this->postJson('/api/checkout/review', $data)->assertOk()->assertJsonPath('data.total_amount', '180.00')->json('data.review_token');
        $this->postJson('/api/checkout', [...$data, 'review_token' => $newToken, 'idempotency_key' => 'test'])->assertCreated()->assertJsonPath('data.order.total_amount', '180.00');
    }

    public function test_checkout_retries_return_the_same_order_and_token_without_more_stock_movements(): void
    {
        $result = $this->place();
        $data = [...$this->payload(), 'review_token' => 'ignored-on-identical-replay', 'idempotency_key' => 'checkout-test'];
        $this->postJson('/api/checkout', $data)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.order.id', $result['order']['id'])->assertJsonPath('data.guest_access_token', $result['guest_access_token']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('inventory_transactions', 1);
        $this->postJson('/api/checkout', [...$data, 'customer_note' => 'A different request'])->assertConflict();
    }

    public function test_insufficient_stock_leaves_no_order_payment_or_reservation_and_keeps_cart(): void
    {
        $variant = $this->variant(['stock_quantity' => 2]);
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $data = $this->payload();
        $token = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');
        $variant->stock_quantity = 1;
        $variant->save();
        $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'stock'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_payment_initialization_failure_rolls_back_created_order_items_and_reserved_stock(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $data = $this->payload();
        $token = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');
        $this->mock(CodOrderPayments::class)->shouldReceive('initialize')->once()->andThrow(new \RuntimeException('Payment write failed'));
        $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'failure'])->assertStatus(500);
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
        $this->assertDatabaseCount('cart_items', 1);
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
    }

    public function test_guest_access_requires_its_secret_token_and_never_exposes_it_in_details(): void
    {
        $result = $this->place();
        $path = '/api/guest/orders/'.$result['order']['order_number'];
        $this->getJson($path)->assertNotFound();
        $this->withHeader('X-Guest-Order-Token', str_repeat('x', 64))->getJson($path)->assertNotFound();
        $this->withHeader('X-Guest-Order-Token', $result['guest_access_token'])->getJson($path)->assertOk()
            ->assertJsonMissingPath('data.order.guest_token_hash')->assertDontSee($result['guest_access_token']);
    }

    public function test_customer_checkout_checks_address_ownership_and_customer_can_only_read_own_order(): void
    {
        $customer = $this->customer();
        $other = $this->customer();
        $address = Address::factory()->create(['customer_id' => $customer->id, ...$this->delivery()]);
        $foreign = Address::factory()->create(['customer_id' => $other->id, ...$this->delivery()]);
        $variant = $this->variant();
        $this->login($customer->user);
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/checkout/review', ['address_id' => $foreign->id, 'payment_method' => 'cod'])->assertNotFound();
        $data = ['address_id' => $address->id, 'payment_method' => 'cod'];
        $token = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');
        $id = $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'customer'])->assertCreated()->assertJsonMissingPath('data.guest_access_token')->json('data.order.id');
        $this->getJson('/api/customer/orders/'.$id)->assertOk();
        $this->login($other->user);
        $this->getJson('/api/customer/orders/'.$id)->assertNotFound();
        $this->postJson('/api/customer/orders/'.$id.'/cancel', ['reason' => 'Not mine'])->assertNotFound();
    }

    public function test_guest_cancellation_releases_stock_exactly_once_and_closes_cod_without_refunding(): void
    {
        $variant = $this->variant();
        $result = $this->place($variant);
        $path = '/api/guest/orders/'.$result['order']['order_number'].'/cancel';
        $this->withHeader('X-Guest-Order-Token', $result['guest_access_token']);
        $this->postJson($path, ['reason' => 'Changed my mind'])->assertOk()->assertJsonPath('data.order.status', 'cancelled');
        $this->postJson($path, ['reason' => 'Retry'])->assertOk();
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
        $this->assertSame(20, $variant->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseHas('payments', ['order_id' => $result['order']['id'], 'status' => 'pending', 'obligation_status' => 'closed']);
        $this->assertDatabaseHas('orders', ['id' => $result['order']['id'], 'payment_status' => 'unpaid']);
    }

    public function test_staff_lifecycle_ships_once_and_delivery_does_not_mark_cod_paid(): void
    {
        $variant = $this->variant();
        $result = $this->place($variant);
        $id = $result['order']['id'];
        $this->staff();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'shipped'])->assertConflict();
        foreach (['confirmed', 'processing', 'shipped', 'shipped', 'delivered'] as $status) {
            $this->patchJson('/api/orders/'.$id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.order.payment_status', 'unpaid');
        }
        $this->assertSame(18, $variant->fresh()->stock_quantity);
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
        $this->assertDatabaseCount('inventory_transactions', 2);
        $this->assertDatabaseCount('order_history', 5);
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'status' => 'pending']);
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'cancelled', 'reason' => 'Too late'])->assertConflict();
    }

    public function test_staff_notes_address_corrections_and_failed_delivery_history_are_protected(): void
    {
        $result = $this->place();
        $id = $result['order']['id'];
        $this->staff();
        $this->postJson('/api/orders/'.$id.'/notes', ['note' => 'Internal secret'])->assertOk();
        $this->patchJson('/api/orders/'.$id.'/delivery-address', ['delivery' => $this->delivery(['address_line1' => 'Corrected house']), 'note' => 'Customer called'])->assertOk();
        foreach (['confirmed', 'processing', 'shipped'] as $status) {
            $this->patchJson('/api/orders/'.$id.'/status', ['status' => $status])->assertOk();
        }
        $this->patchJson('/api/orders/'.$id.'/delivery', ['delivery_status' => 'failed', 'note' => 'Recipient absent', 'courier_name' => 'Courier', 'tracking_reference' => 'TRACK-1'])->assertOk();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'delivered'])->assertConflict();
        $this->patchJson('/api/orders/'.$id.'/delivery', ['delivery_status' => 'in_transit', 'note' => 'Reattempt'])->assertOk();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'delivered'])->assertOk();
        $this->assertDatabaseHas('order_history', ['order_id' => $id, 'action' => 'delivery', 'note' => 'Recipient absent']);
        $this->patchJson('/api/orders/'.$id.'/delivery-address', ['delivery' => $this->delivery(), 'note' => 'Too late'])->assertConflict();
        Auth::forgetGuards();
        $this->withHeader('X-Guest-Order-Token', $result['guest_access_token'])->getJson('/api/guest/orders/'.$result['order']['order_number'])
            ->assertOk()->assertDontSee('Internal secret')->assertDontSee('Recipient absent')->assertJsonPath('data.order.delivery.address_line1', 'Corrected house');
    }

    public function test_catalog_edits_do_not_change_order_item_snapshots(): void
    {
        $variant = $this->variant();
        $result = $this->place($variant);
        $snapshot = DB::table('order_items')->first();
        $variant->price = '500.00';
        $variant->sku = 'NEW-SKU';
        $variant->save();
        $variant->product->name = 'Renamed';
        $variant->product->save();
        $this->assertSame((array) $snapshot, (array) DB::table('order_items')->first());
        $this->withHeader('X-Guest-Order-Token', $result['guest_access_token'])->getJson('/api/guest/orders/'.$result['order']['order_number'])
            ->assertOk()->assertJsonPath('data.order.items.0.unit_price', '100.25');
    }

    public function test_staff_read_write_permissions_and_filters_are_enforced(): void
    {
        $result = $this->place();
        $id = $result['order']['id'];
        $this->getJson('/api/orders')->assertUnauthorized();
        $this->staff([]);
        $this->getJson('/api/orders')->assertForbidden();
        $this->staff(['orders.view']);
        $this->getJson('/api/orders?status=pending&payment_status=unpaid&phone=171234&per_page=1')->assertOk()->assertJsonCount(1, 'data.orders');
        $this->getJson('/api/orders?search='.$result['order']['order_number'])->assertOk()->assertJsonCount(1, 'data.orders');
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'confirmed'])->assertForbidden();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'confirmed', 'payment_status' => 'paid'])->assertForbidden();
        $this->staff();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'confirmed', 'payment_status' => 'paid'])->assertUnprocessable();
    }

    public function test_delivery_coverage_unsupported_payment_and_expired_review_are_rejected(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $this->postJson('/api/checkout/review', $this->payload(['payment_method' => 'card']))->assertUnprocessable();
        config(['orders.coverage_districts' => ['Gazipur']]);
        $this->postJson('/api/checkout/review', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('delivery.district');
        config(['orders.coverage_districts' => []]);
        $token = $this->postJson('/api/checkout/review', $this->payload())->assertOk()->json('data.review_token');
        $this->travel(16)->minutes();
        $this->postJson('/api/checkout', [...$this->payload(), 'review_token' => $token, 'idempotency_key' => 'expired'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_expired_carts_clear_items_and_blocked_customers_cannot_shop_or_checkout(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        Cart::query()->update(['expires_at' => now()->subDay()]);
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
        $customer = $this->customer();
        $this->login($customer->user);
        $customer->user->is_active = false;
        $customer->user->save();
        $this->postJson('/api/checkout/review', ['payment_method' => 'cod', 'delivery' => $this->delivery()])->assertUnauthorized();
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_login_preserves_only_the_session_guest_cart_for_explicit_merge(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 2])->assertOk();
        $guest = Cart::whereNull('customer_id')->sole();
        $customer = $this->customer();
        $this->postJson('/api/auth/customer/login', ['email' => $customer->user->email, 'password' => 'CorrectPassword@123'])->assertOk();
        $this->postJson('/api/cart/merge', ['guest_key' => 'cannot-choose-another-cart'])->assertUnprocessable();
        $this->postJson('/api/cart/merge')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
        $this->postJson('/api/cart/merge')->assertOk()->assertJsonPath('data.cart.items.0.quantity', 2);
        $this->assertSame(0, $guest->items()->count());
        $this->assertSame(0, $variant->fresh()->reserved_quantity);
    }

    public function test_guest_checkout_does_not_link_matching_account_and_other_sessions_cannot_see_cart(): void
    {
        $existing = $this->customer();
        $existing->user->phone = '+8801712345678';
        $existing->user->email = 'guest@example.test';
        $existing->user->save();
        $result = $this->place();
        $this->assertNull(Order::findOrFail($result['order']['id'])->customer_id);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('customers', 1);
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $this->withSession(['guest_cart_token' => str_repeat('x', 64)]);
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
        $this->login($existing->user->fresh());
        $this->getJson('/api/customer/orders/'.$result['order']['id'])->assertNotFound();
    }

    public function test_cod_orders_do_not_expire_and_prepaid_cancellation_only_requests_a_refund(): void
    {
        $result = $this->place();
        $id = $result['order']['id'];
        $this->travel(31)->days();
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
        $this->assertDatabaseHas('orders', ['id' => $id, 'status' => 'pending']);
        $this->assertSame(2, DB::table('stock_reservations')->where('order_id', $id)->value('quantity'));
        DB::table('payments')->where('order_id', $id)->update(['status' => 'successful', 'provider' => 'future-provider']);
        $this->withHeader('X-Guest-Order-Token', $result['guest_access_token'])->postJson('/api/guest/orders/'.$result['order']['order_number'].'/cancel', ['reason' => 'Cancel prepaid test'])->assertOk();
        $this->assertDatabaseHas('payments', ['order_id' => $id, 'status' => 'successful', 'obligation_status' => 'refund_required']);
    }

    public function test_customer_cancellation_is_limited_to_pending_and_confirmed_states(): void
    {
        $customer = $this->customer();
        $this->login($customer->user);
        $variant = $this->variant();
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertOk();
        $data = ['delivery' => $this->delivery(), 'payment_method' => 'cod'];
        $token = $this->postJson('/api/checkout/review', $data)->assertOk()->json('data.review_token');
        $id = $this->postJson('/api/checkout', [...$data, 'review_token' => $token, 'idempotency_key' => 'early-cancel'])->assertCreated()->json('data.order.id');
        $this->staff();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'confirmed'])->assertOk();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'processing'])->assertOk();
        $this->login($customer->user->fresh());
        $this->postJson('/api/customer/orders/'.$id.'/cancel', ['reason' => 'Too late for customer'])->assertConflict();
        $this->staff();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'shipped'])->assertOk();
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'cancelled', 'reason' => 'No automatic restock'])->assertConflict();
        $this->assertSame(19, $variant->fresh()->stock_quantity);
    }

    public function test_saved_order_prices_and_items_cannot_be_edited_and_staff_dates_filter_independently(): void
    {
        $result = $this->place();
        $order = Order::findOrFail($result['order']['id']);
        $this->staff();
        $this->getJson('/api/orders?to='.now()->format('Y-m-d'))->assertOk()->assertJsonCount(1, 'data.orders');
        try {
            $order->total_amount = '1.00';
            $order->save();
            $this->fail('Placed prices must not be editable.');
        } catch (\LogicException) {
            $this->assertSame('260.50', $order->fresh()->total_amount);
        }
        $item = $order->items()->firstOrFail();
        $this->expectException(\LogicException::class);
        $item->quantity = 9;
        $item->save();
    }

    public function test_shop_writes_require_csrf_outside_the_test_environment(): void
    {
        $variant = $this->variant();
        $this->app->instance('env', 'local');
        $this->postJson('/api/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertStatus(419);
        $this->postJson('/api/checkout/review', $this->payload())->assertStatus(419);
        $this->assertDatabaseCount('orders', 0);
    }
}
