<?php

namespace Tests\Feature;

use App\Auth\Domain\Services\AuthService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Customers\Models\Customer;
use App\Orders\Domain\Services\DeliveryChargeService;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CheckoutDeliveryTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function variant(int $stock = 10): ProductVariant
    {
        return ProductVariant::factory()->create(['product_id' => Product::factory()->create(['status' => 'published'])->id,
            'stock_quantity' => $stock, 'price' => '100.00', 'cost_price' => '45.00']);
    }

    /** @return array<string, mixed> */
    private function payload(string $zone = 'inside_dhaka'): array
    {
        return ['payment_method' => 'cod', 'delivery_zone' => $zone, 'contact' => ['name' => 'Checkout Buyer', 'phone' => '+8801712345678']];
    }

    private function fillCart(ProductVariant $variant): void
    {
        $this->postJson('/api/cart/sync', ['items' => [['variant_id' => $variant->id, 'quantity' => 2]]])->assertOk();
    }

    private function signIn(string $role, array $permissions = []): User
    {
        $user = User::factory()->create(['role' => $role, 'permissions' => $permissions]);
        $this->actingAs($user, $role === 'customer' ? 'customer' : 'staff')->withSession(['auth_started_at' => now()->timestamp, 'auth_fingerprint' => app(AuthService::class)->fingerprint($user)]);

        return $user;
    }

    public function test_guest_checkout_requires_only_name_phone_and_delivery_zone(): void
    {
        $variant = $this->variant();
        $this->fillCart($variant);
        $payload = $this->payload();
        $quote = $this->postJson('/api/checkout/review', $payload)->assertOk()->assertJsonPath('data.shipping_fee', '60.00')->assertJsonPath('data.total_amount', '260.00');

        $response = $this->postJson('/api/checkout', [...$payload, 'review_token' => $quote->json('data.review_token'), 'idempotency_key' => 'simple-guest-checkout'])
            ->assertCreated()->assertJsonPath('data.order.total_amount', '260.00')->assertJsonPath('data.order.delivery.address_line1', null);

        $this->assertDatabaseHas('orders', ['id' => $response->json('data.order.id'), 'guest_name' => 'Checkout Buyer', 'guest_phone' => '+8801712345678', 'guest_email' => null, 'shipping_fee' => '60.00']);
        $this->assertDatabaseHas('stock_reservations', ['order_id' => $response->json('data.order.id'), 'quantity' => 2, 'status' => 'reserved']);
        $this->getJson('/api/cart')->assertOk()->assertJsonCount(0, 'data.cart.items');
    }

    public function test_ordering_and_status_updates_ignore_stock_while_inventory_is_disabled(): void
    {
        config(['catalog.inventory_enabled' => false]);
        $variant = $this->variant(0);
        $this->getJson('/api/catalog/products/'.$variant->product->id)->assertOk()
            ->assertJsonPath('data.product.variants.0.available', true)
            ->assertJsonPath('data.product.variants.0.inventory_enabled', false);
        $this->fillCart($variant);
        $payload = $this->payload();
        $review = $this->postJson('/api/checkout/review', $payload)->assertOk();
        $response = $this->postJson('/api/checkout', [...$payload, 'review_token' => $review->json('data.review_token'), 'idempotency_key' => 'no-inventory-order'])->assertCreated();
        $id = $response->json('data.order.id');
        $this->signIn('admin');

        foreach (['confirmed', 'processing', 'shipped', 'delivered'] as $status) {
            $this->patchJson('/api/orders/'.$id.'/status', ['status' => $status])->assertOk()->assertJsonPath('data.order.status', $status);
        }
        $this->patchJson('/api/orders/'.$id.'/status', ['status' => 'returned', 'reason' => 'Customer return', 'restock' => true])->assertOk();
        $this->deleteJson('/api/orders/'.$id, ['reason' => 'Remove completed order'])->assertOk();

        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 0, 'reserved_quantity' => 0]);
        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseCount('inventory_transactions', 0);
    }

    public function test_manual_order_and_cancellation_do_not_require_inventory_records_when_disabled(): void
    {
        config(['catalog.inventory_enabled' => false]);
        $variant = $this->variant(0);
        $this->signIn('admin');
        $response = $this->postJson('/api/orders', [
            'idempotency_key' => 'manual-no-inventory', 'contact' => ['name' => 'Buyer', 'phone' => '+8801712345678'],
            'delivery' => ['recipient_name' => 'Buyer', 'phone' => '+8801712345678', 'address_line1' => 'Road 1', 'city' => 'Dhaka', 'district' => 'Dhaka', 'division' => 'Dhaka'],
            'shipping_fee' => '60.00', 'items' => [['variant_id' => $variant->id, 'quantity' => 2]],
        ])->assertOk();

        $this->deleteJson('/api/orders/'.$response->json('data.order.id'), ['reason' => 'Cancel duplicate'])->assertOk();

        $this->assertDatabaseCount('stock_reservations', 0);
        $this->assertDatabaseHas('product_variants', ['id' => $variant->id, 'stock_quantity' => 0]);
    }

    public function test_outside_dhaka_and_optional_email_address_are_saved_correctly(): void
    {
        $variant = $this->variant();
        $this->fillCart($variant);
        $payload = $this->payload('outside_dhaka');
        $payload['contact']['email'] = 'BUYER@EXAMPLE.TEST';
        $payload['delivery'] = ['address_line1' => 'House 5, Chattogram'];
        $quote = $this->postJson('/api/checkout/review', $payload)->assertOk()->assertJsonPath('data.shipping_fee', '120.00');

        $this->postJson('/api/checkout', [...$payload, 'review_token' => $quote->json('data.review_token'), 'idempotency_key' => 'outside-checkout'])
            ->assertCreated()->assertJsonPath('data.order.total_amount', '320.00')->assertJsonPath('data.order.contact.email', 'buyer@example.test')
            ->assertJsonPath('data.order.delivery.delivery_zone', 'outside_dhaka')->assertJsonPath('data.order.delivery.address_line1', 'House 5, Chattogram');
    }

    public function test_required_fields_and_invalid_zone_email_phone_are_rejected(): void
    {
        $this->fillCart($this->variant());
        $this->postJson('/api/checkout/review', ['payment_method' => 'cod', 'delivery_zone' => 'inside_dhaka'])->assertUnprocessable()->assertJsonValidationErrors(['contact.name', 'contact.phone']);
        $payload = $this->payload('untrusted-zone');
        $this->postJson('/api/checkout/review', $payload)->assertUnprocessable()->assertJsonValidationErrors('delivery_zone');
        $payload = $this->payload();
        $payload['contact']['email'] = 'bad-email';
        $payload['contact']['phone'] = '123';
        $this->postJson('/api/checkout/review', $payload)->assertUnprocessable()->assertJsonValidationErrors(['contact.email', 'contact.phone']);
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_only_admin_can_update_charges_and_stale_updates_are_rejected(): void
    {
        $this->getJson('/api/checkout/delivery-charges')->assertOk()->assertJsonPath('data.inside_dhaka', '60.00')->assertJsonPath('data.outside_dhaka', '120.00');
        $this->patchJson('/api/orders/delivery-charges', ['inside_dhaka' => '80.00', 'outside_dhaka' => '150.00', 'version' => 0])->assertUnauthorized();
        $this->signIn('staff', ['orders.view', 'orders.process']);
        $this->getJson('/api/orders/delivery-charges')->assertOk();
        $this->patchJson('/api/orders/delivery-charges', ['inside_dhaka' => '80.00', 'outside_dhaka' => '150.00', 'version' => 0])->assertForbidden();
        $admin = $this->signIn('admin');

        $this->patchJson('/api/orders/delivery-charges', ['inside_dhaka' => '80.50', 'outside_dhaka' => '150.25', 'version' => 0])
            ->assertOk()->assertJsonPath('data.inside_dhaka', '80.50')->assertJsonPath('data.version', 1);

        $this->assertDatabaseHas('delivery_charge_settings', ['id' => 1, 'inside_dhaka' => '80.50', 'outside_dhaka' => '150.25', 'updated_by' => $admin->id]);
        $this->assertDatabaseHas('staff_activity', ['actor_id' => $admin->id, 'action' => 'delivery_charges_updated']);
        $this->patchJson('/api/orders/delivery-charges', ['inside_dhaka' => '1.00', 'outside_dhaka' => '1.00', 'version' => 0])->assertConflict();
        $this->getJson('/api/checkout/delivery-charges')->assertOk()->assertJsonPath('data.outside_dhaka', '150.25');
    }

    public function test_invalid_charges_are_rejected_without_changing_settings(): void
    {
        $this->signIn('admin');
        $this->patchJson('/api/orders/delivery-charges', ['inside_dhaka' => '-1', 'outside_dhaka' => '1.234', 'version' => 0])->assertUnprocessable()->assertJsonValidationErrors(['inside_dhaka', 'outside_dhaka']);
        $this->assertDatabaseHas('delivery_charge_settings', ['id' => 1, 'inside_dhaka' => '60.00', 'version' => 0]);
    }

    public function test_changed_delivery_charges_invalidate_review_and_do_not_reprice_placed_orders(): void
    {
        $this->fillCart($this->variant());
        $payload = $this->payload();
        $token = $this->postJson('/api/checkout/review', $payload)->assertOk()->json('data.review_token');
        $admin = User::factory()->create(['role' => 'admin']);
        app(DeliveryChargeService::class)->update($admin, ['inside_dhaka' => '90.00', 'outside_dhaka' => '180.00', 'version' => 0]);

        $this->postJson('/api/checkout', [...$payload, 'review_token' => $token, 'idempotency_key' => 'changed-charge'])->assertUnprocessable()->assertJsonValidationErrors('review_token');
        $this->assertDatabaseCount('orders', 0);
        $review = $this->postJson('/api/checkout/review', $payload)->assertOk()->assertJsonPath('data.total_amount', '290.00');
        $response = $this->postJson('/api/checkout', [...$payload, 'review_token' => $review->json('data.review_token'), 'idempotency_key' => 'changed-charge'])->assertCreated();
        app(DeliveryChargeService::class)->update($admin, ['inside_dhaka' => '95.00', 'outside_dhaka' => '200.00', 'version' => 1]);

        $this->assertDatabaseHas('orders', ['id' => $response->json('data.order.id'), 'shipping_fee' => '90.00', 'total_amount' => '290.00']);
    }

    public function test_cart_sync_replaces_items_and_rolls_back_invalid_stock(): void
    {
        $first = $this->variant();
        $second = $this->variant(1);
        $this->fillCart($first);
        $this->postJson('/api/cart/sync', ['items' => [['variant_id' => $second->id, 'quantity' => 2]]])->assertUnprocessable();
        $this->getJson('/api/cart')->assertOk()->assertJsonPath('data.cart.items.0.variant_id', $first->id)->assertJsonPath('data.cart.items.0.quantity', 2);

        $this->postJson('/api/cart/sync', ['items' => [['variant_id' => $second->id, 'quantity' => 1]]])->assertOk()->assertJsonCount(1, 'data.cart.items')->assertJsonPath('data.cart.items.0.variant_id', $second->id);

        $this->assertDatabaseMissing('cart_items', ['variant_id' => $first->id]);
        $this->assertDatabaseHas('cart_items', ['variant_id' => $second->id, 'quantity' => 1]);
    }

    public function test_cart_sync_rejects_duplicate_variants_and_client_price_overrides(): void
    {
        $variant = $this->variant();
        $this->postJson('/api/cart/sync', ['items' => [['variant_id' => $variant->id, 'quantity' => 1], ['variant_id' => $variant->id, 'quantity' => 1]]])->assertUnprocessable();
        $this->postJson('/api/cart/sync', ['items' => [['variant_id' => $variant->id, 'quantity' => 1, 'price' => '1.00']]])->assertUnprocessable();
        $this->assertDatabaseCount('cart_items', 0);
    }

    public function test_registered_customer_can_use_the_simple_checkout_form(): void
    {
        $user = $this->signIn('customer');
        Customer::factory()->create(['user_id' => $user->id]);
        $this->fillCart($this->variant());
        $payload = $this->payload();
        $quote = $this->postJson('/api/checkout/review', $payload)->assertOk();

        $response = $this->postJson('/api/checkout', [...$payload, 'review_token' => $quote->json('data.review_token'), 'idempotency_key' => 'customer-simple-checkout'])->assertCreated()->assertJsonMissingPath('data.guest_access_token');

        $this->assertNotNull(Order::findOrFail($response->json('data.order.id'))->customer_id);
    }
}
