<?php

namespace App\Orders\Domain\Services;

use App\Catalog\Domain\Services\InventoryService;
use App\Customers\Requests\AddressRequest;
use App\Orders\Events\OrderChanged;
use App\Orders\Models\Cart;
use App\Orders\Models\Order;
use App\Payments\Domain\Services\CodOrderPayments;
use App\Users\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutService
{
    public function __construct(private readonly CartService $carts, private readonly InventoryService $inventory, private readonly CodOrderPayments $payments, private readonly OrderCustomerService $customers, private readonly DeliveryChargeService $deliveryCharges) {}

    public function review(Request $request, array $data): array
    {
        return DB::transaction(function () use ($request, $data): array {
            $identity = $this->identity($request, $data);

            return $this->carts->locked($this->carts->resolve($request), function (Cart $cart) use ($identity): array {
                $quote = $this->quote($cart, $identity);
                $expires = now()->addMinutes(config('orders.review_minutes'));

                return $quote + ['review_token' => Crypt::encryptString(json_encode([
                    'cart_id' => $cart->id, 'digest' => $this->digest([$quote, $identity]), 'expires' => $expires->timestamp,
                ], JSON_THROW_ON_ERROR)), 'expires_at' => $expires->toISOString()];
            });
        }, 3);
    }

    public function place(Request $request, array $data): array
    {
        return DB::transaction(function () use ($request, $data): array {
            $identity = $this->identity($request, $data);

            return $this->carts->locked($this->carts->resolve($request), function (Cart $cart) use ($request, $data, $identity): array {
                $scope = $cart->customer_id !== null ? 'customer-'.$cart->customer_id : 'guest-'.$cart->guest_key;
                $key = hash_hmac('sha256', $scope.'|'.$data['idempotency_key'], config('app.key'));
                $fingerprint = $this->digest([$cart->id, $identity]);
                $existing = Order::withTrashed()->where('checkout_key', $key)->first();
                if ($existing !== null) {
                    abort_if($existing->trashed(), 409, 'This order has been deleted.');
                    abort_unless(hash_equals($existing->checkout_fingerprint, $fingerprint), 409);

                    return $this->result($existing, true);
                }
                try {
                    $review = json_decode(Crypt::decryptString($data['review_token']), true, 512, JSON_THROW_ON_ERROR);
                } catch (DecryptException|\JsonException) {
                    throw ValidationException::withMessages(['review_token' => 'The checkout review is invalid. Review checkout again.']);
                }
                $quote = $this->quote($cart, $identity);
                if (($review['cart_id'] ?? null) !== $cart->id || ($review['expires'] ?? 0) <= now()->timestamp
                    || ! hash_equals($review['digest'] ?? '', $this->digest([$quote, $identity]))) {
                    throw ValidationException::withMessages(['review_token' => 'Reviewed prices, cart items, or delivery charges have changed or expired. Review checkout again and confirm the new total.']);
                }
                $order = new Order;
                $order->order_number = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(16));
                $order->customer_id = $cart->customer_id;
                $order->checkout_key = $key;
                $order->checkout_fingerprint = $fingerprint;
                $order->status = 'pending';
                $order->payment_status = 'unpaid';
                $order->payment_method = 'cod';
                $order->contact_snapshot = $identity['contact'];
                $order->shipping_address = json_encode($identity['delivery'], JSON_THROW_ON_ERROR);
                $order->customer_note = $identity['customer_note'];
                $order->currency = config('orders.currency');
                $order->subtotal = $quote['subtotal'];
                $order->discount_amount = '0.00';
                $order->shipping_fee = $quote['shipping_fee'];
                $order->tax_amount = '0.00';
                $order->total_amount = $quote['total_amount'];
                if ($cart->customer_id === null) {
                    $order->guest_name = $identity['contact']['name'];
                    $order->guest_email = $identity['contact']['email'] ?? null;
                    $order->guest_phone = $identity['contact']['phone'];
                }
                $order->save();
                if ($order->customer_id === null) {
                    $order->guest_token_hash = hash('sha256', $this->guestToken($order));
                    $order->save();
                }
                $productCost = 0;
                $hasMissingCost = false;
                $costs = DB::table('product_variants')->whereIn('id', array_column($quote['items'], 'variant_id'))->pluck('cost_price', 'id');
                foreach ($quote['items'] as $item) {
                    $cost = $costs[$item['variant_id']] ?? null;
                    $hasMissingCost = $hasMissingCost || $cost === null;
                    $productCost += $cost === null ? 0 : Money::minor((string) $cost) * $item['quantity'];
                    DB::table('order_items')->insert([
                        'order_id' => $order->id, 'product_id' => $item['product_id'], 'variant_id' => $item['variant_id'],
                        'product_name' => $item['name'], 'sku' => $item['sku'], 'variant_attributes' => json_encode($item['attributes'], JSON_THROW_ON_ERROR),
                        'unit_price' => $item['unit_price'], 'quantity' => $item['quantity'], 'discount_amount' => '0.00',
                        'line_total' => $item['line_total'], 'currency' => $order->currency, 'created_at' => now(),
                    ]);
                    if (config('catalog.inventory_enabled')) {
                        $this->inventory->reserveForOrder($request->user(), $item['variant_id'], $order->id, $item['quantity']);
                    }
                }
                $order->product_cost = $hasMissingCost ? null : Money::decimal($productCost);
                $order->save();
                $this->payments->initialize($order);
                DB::table('order_history')->insert([
                    'order_id' => $order->id, 'actor_id' => $request->user()?->id, 'action' => 'placed',
                    'previous_status' => null, 'new_status' => 'pending', 'created_at' => now(),
                ]);
                $cart->items()->delete();
                $cart->converted_at = now();
                $cart->save();
                event(new OrderChanged($order->id, 'pending'));

                return $this->result($order, false);
            });
        }, 3);
    }

    public function identity(Request $request, array $data): array
    {
        $user = $request->user();
        if (isset($data['delivery_zone'])) {
            if ($user !== null) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $customer = $user->customer()->lockForUpdate()->firstOrFail();
                $this->customers->assertCanOrder($customer, $user);
            }
            $contact = $data['contact'];
            if (isset($contact['email'])) {
                $contact['email'] = strtolower(trim($contact['email']));
            }

            return ['contact' => $contact, 'delivery' => [
                'recipient_name' => $contact['name'], 'phone' => $contact['phone'],
                'address_line1' => $data['delivery']['address_line1'] ?? null,
                'district' => $data['delivery_zone'] === 'inside_dhaka' ? 'Dhaka' : 'Outside Dhaka',
                'delivery_zone' => $data['delivery_zone'], 'country_code' => 'BD',
            ], 'customer_note' => $data['customer_note'] ?? null, 'payment_method' => 'cod'];
        }
        if ($user !== null) {
            $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            $customer = $user->customer()->lockForUpdate()->firstOrFail();
            $this->customers->assertCanOrder($customer, $user);
            $delivery = isset($data['address_id']) ? $customer->addresses()->findOrFail($data['address_id'])->only(array_keys(AddressRequest::deliveryRules())) : $data['delivery'];
            unset($delivery['id'], $delivery['label'], $delivery['is_default']);
            $contact = ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone ?? $delivery['phone']];
        } else {
            $contact = $data['contact'];
            $delivery = $data['delivery'];
            if (isset($contact['email'])) {
                $contact['email'] = strtolower(trim($contact['email']));
            }
        }
        $delivery = Validator::make($delivery, AddressRequest::deliveryRules())->validate();
        unset($delivery['label'], $delivery['is_default']);
        $delivery['country_code'] ??= 'BD';
        $district = mb_strtolower(trim($delivery['district']));
        $coverage = array_map(fn (string $value): string => mb_strtolower(trim($value)), config('orders.coverage_districts'));
        if ($coverage !== [] && ! in_array($district, $coverage, true)) {
            throw ValidationException::withMessages(['delivery.district' => 'Delivery is unavailable in this district.']);
        }

        return ['contact' => $contact, 'delivery' => $delivery, 'customer_note' => $data['customer_note'] ?? null, 'payment_method' => 'cod'];
    }

    public function guestToken(Order $order): string
    {
        return hash_hmac('sha256', 'guest-order|'.$order->id.'|'.$order->checkout_key, config('app.key'));
    }

    private function quote(Cart $cart, array $identity): array
    {
        $summary = $this->carts->summary($cart, true, true);
        $district = mb_strtolower(trim($identity['delivery']['district']));
        $zone = $identity['delivery']['delivery_zone'] ?? ($district === 'dhaka' ? 'inside_dhaka' : 'outside_dhaka');
        $charges = $this->deliveryCharges->rates(true);
        $fee = Money::minor($charges[$zone]);

        return ['items' => $summary['items'], 'subtotal' => $summary['subtotal'], 'discount_amount' => '0.00',
            'shipping_fee' => Money::decimal($fee), 'tax_amount' => '0.00',
            'total_amount' => Money::decimal(Money::minor($summary['subtotal']) + $fee), 'currency' => config('orders.currency'), 'payment_method' => 'cod'];
    }

    private function result(Order $order, bool $replayed): array
    {
        return ['order' => $order->load('items'), 'replayed' => $replayed,
            'guest_access_token' => $order->customer_id === null ? $this->guestToken($order) : null];
    }

    private function digest(array $value): string
    {
        $sort = function (array $array) use (&$sort): array {
            foreach ($array as &$child) {
                if (is_array($child)) {
                    $child = $sort($child);
                }
            }
            unset($child);
            ksort($array);

            return $array;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR));
    }
}
