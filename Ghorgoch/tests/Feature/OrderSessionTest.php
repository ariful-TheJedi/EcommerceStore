<?php

namespace Tests\Feature;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Order;
use App\Users\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class OrderSessionTest extends TestCase
{
    use LazilyRefreshDatabase;

    /** @param array<string, string> $cookies
     * @param  array<string, mixed>  $data
     */
    private function browserRequest(array &$cookies, string $method, string $path, array $data = [], ?string $token = null): TestResponse
    {
        Auth::forgetGuards();
        app()->forgetInstance('auth.driver');
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) {
            $headers['HTTP_X_CSRF_TOKEN'] = $token;
        }
        $response = $this->call($method, $path, [], $cookies, [], $headers, json_encode($data, JSON_THROW_ON_ERROR));
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === config('session.cookie')) {
                $cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    public function test_opening_orders_and_reports_preserves_real_admin_cookie_session(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $user = User::factory()->create(['role' => 'admin', 'password' => 'SessionPassword123!']);
        $order = Order::factory()->create();
        $cookies = [];
        $this->browserRequest($cookies, 'POST', '/api/auth/staff/login', ['email' => $user->email, 'password' => 'SessionPassword123!'])->assertOk();
        $this->browserRequest($cookies, 'GET', '/api/auth/staff/me')->assertOk();

        $this->browserRequest($cookies, 'GET', '/api/orders')->assertOk();
        $this->browserRequest($cookies, 'GET', '/api/orders/report')->assertOk();
        $this->browserRequest($cookies, 'GET', '/api/orders/'.$order->id)->assertOk();

        $token = $this->browserRequest($cookies, 'GET', '/api/auth/csrf-token')->assertOk()->json('data.csrf_token');
        $this->app->instance('env', 'local');
        $this->browserRequest($cookies, 'POST', '/api/orders/'.$order->id.'/notes', ['note' => 'Missing token'])->assertStatus(419);
        $this->browserRequest($cookies, 'POST', '/api/orders/'.$order->id.'/notes', ['note' => 'Valid browser session'], $token)->assertOk();
        $this->assertDatabaseHas('order_history', ['order_id' => $order->id, 'action' => 'staff_note', 'note' => 'Valid browser session']);

        $this->browserRequest($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_permission_denial_does_not_destroy_logged_in_staff_session(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $user = User::factory()->create(['role' => 'staff', 'permissions' => [], 'password' => 'SessionPassword123!']);
        $cookies = [];
        $this->browserRequest($cookies, 'POST', '/api/auth/staff/login', ['email' => $user->email, 'password' => 'SessionPassword123!'])->assertOk();

        $this->browserRequest($cookies, 'GET', '/api/orders')->assertForbidden();
        $this->browserRequest($cookies, 'GET', '/api/orders/report')->assertForbidden();

        $this->browserRequest($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
    }

    public function test_admin_can_place_storefront_guest_order_without_losing_admin_session(): void
    {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        $user = User::factory()->create(['role' => 'admin', 'password' => 'SessionPassword123!']);
        $product = Product::factory()->create(['status' => 'published']);
        $variant = ProductVariant::factory()->create(['product_id' => $product->id, 'stock_quantity' => 10, 'price' => '100.00']);
        $cookies = [];
        $this->browserRequest($cookies, 'POST', '/api/auth/staff/login', ['email' => $user->email, 'password' => 'SessionPassword123!'])->assertOk();
        $this->app->instance('env', 'local');
        $token = $this->browserRequest($cookies, 'GET', '/api/auth/csrf-token')->assertOk()->json('data.csrf_token');
        $this->browserRequest($cookies, 'POST', '/api/cart/sync', ['items' => [['variant_id' => $variant->id, 'quantity' => 1]]], $token)->assertOk();
        $payload = ['delivery_zone' => 'inside_dhaka', 'payment_method' => 'cod', 'contact' => ['name' => 'Storefront Buyer', 'phone' => '+8801712345678']];
        $review = $this->browserRequest($cookies, 'POST', '/api/checkout/review', $payload, $token)->assertOk();

        $response = $this->browserRequest($cookies, 'POST', '/api/checkout', [...$payload, 'review_token' => $review->json('data.review_token'), 'idempotency_key' => 'admin-browser-guest-order'], $token)
            ->assertCreated()->assertJsonPath('data.order.total_amount', '160.00')->assertJsonStructure(['data' => ['guest_access_token']]);

        $this->assertDatabaseHas('orders', ['id' => $response->json('data.order.id'), 'customer_id' => null, 'guest_name' => 'Storefront Buyer']);
        $this->browserRequest($cookies, 'GET', '/api/auth/staff/me')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->browserRequest($cookies, 'GET', '/api/orders')->assertOk();
        $this->assertDatabaseHas('sessions', ['user_id' => $user->id]);
        $this->browserRequest($cookies, 'GET', '/api/customer/orders')->assertUnauthorized();
    }
}
