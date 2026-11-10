<?php

namespace App\Orders\Domain\Services;

use App\Catalog\Domain\Services\InventoryService;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Orders\Events\OrderChanged;
use App\Orders\Models\Order;
use App\Payments\Domain\Services\CodOrderPayments;
use App\Users\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderService
{
    public function __construct(private readonly InventoryService $inventory, private readonly CodOrderPayments $payments) {}

    /** @param array<string, mixed> $data */
    public function createManual(Request $request, array $data): Order
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return DB::transaction(function () use ($request, $data): Order {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $key = hash('sha256', 'manual|'.$request->user()->id.'|'.$data['idempotency_key']);
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = Order::withTrashed()->where('checkout_key', $key)->first();
            if ($existing) {
                abort_if($existing->trashed(), 409, 'This order has been deleted.');
                abort_unless(hash_equals($existing->checkout_fingerprint, $fingerprint), 409, 'This submission key was already used for a different order.');

                return $existing->load('items');
            }
            $ids = array_column($data['items'], 'variant_id');
            $productIds = ProductVariant::whereIn('id', $ids)->pluck('product_id');
            $products = Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $variants = ProductVariant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $subtotal = 0;
            $cost = 0;
            $missingCost = false;
            $lines = [];
            foreach ($data['items'] as $line) {
                $variant = $variants->get($line['variant_id']);
                if (! $variant || ! $variant->is_active || $products->get($variant->product_id)?->status !== 'published') {
                    throw ValidationException::withMessages(['items' => 'Choose active variants from published products.']);
                }
                $unit = Money::minor($variant->effectivePrice());
                $lineTotal = $unit * $line['quantity'];
                $subtotal += $lineTotal;
                $missingCost = $missingCost || $variant->cost_price === null;
                $cost += $variant->cost_price === null ? 0 : Money::minor($variant->cost_price) * $line['quantity'];
                $lines[] = ['product_id' => $variant->product_id, 'variant_id' => $variant->id,
                    'product_name' => $products[$variant->product_id]->name, 'sku' => $variant->sku,
                    'variant_attributes' => json_encode($variant->attributes, JSON_THROW_ON_ERROR),
                    'quantity' => $line['quantity'], 'unit_price' => Money::decimal($unit), 'line_total' => Money::decimal($lineTotal),
                    'discount_amount' => '0.00', 'currency' => config('orders.currency'), 'created_at' => now()];
            }
            $order = new Order;
            $order->order_number = 'ORD-'.now()->format('Ymd').'-'.Str::upper(Str::random(16));
            $order->status = 'pending';
            $order->payment_status = 'unpaid';
            $order->payment_method = 'cod';
            $order->currency = config('orders.currency');
            $order->processed_by = $request->user()->id;
            $order->checkout_key = $key;
            $order->checkout_fingerprint = $fingerprint;
            $order->contact_snapshot = $data['contact'];
            $order->guest_name = $data['contact']['name'];
            $order->guest_phone = $data['contact']['phone'];
            $order->guest_email = $data['contact']['email'] ?? null;
            $order->shipping_address = json_encode($data['delivery'] + ['country_code' => 'BD'], JSON_THROW_ON_ERROR);
            $order->customer_note = $data['customer_note'] ?? null;
            $order->subtotal = Money::decimal($subtotal);
            $order->shipping_fee = Money::decimal(Money::minor((string) $data['shipping_fee']));
            $order->discount_amount = '0.00';
            $order->tax_amount = '0.00';
            $order->total_amount = Money::decimal($subtotal + Money::minor($order->shipping_fee));
            $order->product_cost = $missingCost ? null : Money::decimal($cost);
            $order->save();
            foreach ($lines as $line) {
                DB::table('order_items')->insert(['order_id' => $order->id, ...$line]);
                if (config('catalog.inventory_enabled')) {
                    $this->inventory->reserveForOrder($request->user(), $line['variant_id'], $order->id, $line['quantity']);
                }
            }
            $this->payments->initialize($order);
            $this->history($order, $request, 'placed', 'pending', 'Order created manually by staff.');
            event(new OrderChanged($order->id, 'pending'));

            return $order->load('items');
        }, 3);
    }

    /** @param list<int> $orderIds */
    public function deleteMultiple(Request $request, array $orderIds, string $reason): int
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return DB::transaction(function () use ($request, $orderIds, $reason): int {
            sort($orderIds);
            foreach ($orderIds as $orderId) {
                $this->delete($request, Order::findOrFail($orderId), $reason);
            }

            return count($orderIds);
        }, 3);
    }

    public function delete(Request $request, Order $order, string $reason): void
    {
        Gate::forUser($request->user())->authorize('orders.process');
        $this->locked($order, function (Order $order) use ($request, $reason): Order {
            if (in_array($order->status, ['pending', 'confirmed', 'processing'], true)) {
                $order = $this->transition($request, $order, ['status' => 'cancelled', 'reason' => $reason]);
            }
            $this->history($order, $request, 'deleted', $order->status, $reason);
            $order->delete();

            return $order;
        });
    }

    public function find(Request $request): Order
    {
        $audience = $request->route()->defaults['order_audience'];
        $query = Order::query();
        if ($audience === 'guest') {
            $token = $request->header('X-Guest-Order-Token');
            abort_unless(is_string($token) && strlen($token) === 64, 404);

            return $query->whereNull('customer_id')->where('order_number', $request->route('number'))
                ->where('guest_token_hash', hash('sha256', $token))->with('items')->firstOrFail();
        }
        if ($audience === 'customer') {
            $customer = $request->user()->customer()->firstOrFail();
            $query->where('customer_id', $customer->id);
        }

        return $query->with('items')->findOrFail((int) $request->route('order'));
    }

    public function transition(Request $request, Order $order, array $data): Order
    {
        $staff = $request->route()->defaults['order_audience'] === 'staff';
        if ($staff) {
            Gate::forUser($request->user())->authorize('orders.process');
        }

        return $this->locked($order, function (Order $order) use ($request, $data, $staff): Order {
            $status = $data['status'];
            if ($order->status === $status) {
                return $order;
            }
            if (! $staff && ($status !== 'cancelled' || ! in_array($order->status, config('orders.customer_cancellation_states'), true))) {
                abort(409);
            }
            abort_unless(in_array($status, config('orders.transitions.'.$order->status, []), true), 409);
            if ($status === 'cancelled' && trim($data['reason'] ?? '') === '') {
                throw ValidationException::withMessages(['reason' => 'A cancellation reason is required.']);
            }
            if ($status === 'delivered') {
                abort_unless($order->delivery_status === 'in_transit', 409);
            }
            $previous = $order->status;
            if (config('catalog.inventory_enabled') && in_array($status, ['shipped', 'cancelled'], true)) {
                foreach ($order->items as $item) {
                    if ($status === 'shipped') {
                        $this->inventory->dispatchForOrder($request->user(), $item->variant_id, $order->id, $item->quantity);
                    } else {
                        $this->inventory->finishForOrder($request->user(), $item->variant_id, $order->id, 'release');
                    }
                }
            }
            if ($status === 'cancelled') {
                $this->payments->cancelled($order);
            }
            if ($status === 'returned') {
                if (trim($data['reason'] ?? '') === '') {
                    throw ValidationException::withMessages(['reason' => 'A return reason is required.']);
                }
                $order->return_restocked = (bool) $data['restock'];
                if ($order->return_restocked && config('catalog.inventory_enabled')) {
                    foreach ($order->items as $item) {
                        $this->inventory->returnForOrder($request->user(), $item->variant_id, $order->id);
                    }
                }
                $this->payments->cancelled($order);
                $order->delivery_status = 'returned';
            }
            $order->status = $status;
            $order->{$status.'_at'} = now();
            if ($status === 'shipped') {
                $order->delivery_status = 'in_transit';
            }
            if ($status === 'delivered') {
                $order->delivery_status = 'delivered';
            }
            $order->save();
            $this->history($order, $request, 'status', $previous, $data['reason'] ?? $data['note'] ?? null);
            event(new OrderChanged($order->id, $status));

            return $order;
        });
    }

    public function delivery(Request $request, Order $order, array $data): Order
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return $this->locked($order, function (Order $order) use ($request, $data): Order {
            abort_if(in_array($order->status, ['cancelled', 'delivered', 'returned'], true), 409);
            if (isset($data['delivery_status'])) {
                abort_unless($order->status === 'shipped', 409);
                if ($data['delivery_status'] === 'failed' && trim($data['note'] ?? '') === '') {
                    throw ValidationException::withMessages(['note' => 'Record why delivery failed.']);
                }
            }
            $before = $order->only(['delivery_status', 'courier_name', 'tracking_reference', 'delivery_method']);
            foreach (['delivery_status', 'courier_name', 'tracking_reference', 'delivery_method'] as $field) {
                if (array_key_exists($field, $data)) {
                    $order->{$field} = $data[$field];
                }
            }
            $order->save();
            $this->history($order, $request, 'delivery', $order->status, $data['note'] ?? null, $before, $order->only(array_keys($before)));

            return $order;
        });
    }

    public function address(Request $request, Order $order, array $delivery, string $note): Order
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return $this->locked($order, function (Order $order) use ($request, $delivery, $note): Order {
            abort_unless(in_array($order->status, ['pending', 'confirmed', 'processing'], true), 409);
            $delivery['country_code'] ??= 'BD';
            $old = json_decode($order->shipping_address, true, 512, JSON_THROW_ON_ERROR);
            if (mb_strtolower(trim($old['district'] ?? '')) !== mb_strtolower(trim($delivery['district']))) {
                throw ValidationException::withMessages(['delivery.district' => 'Cancel and recreate orders that require a different delivery district or charge.']);
            }
            $order->shipping_address = json_encode($delivery, JSON_THROW_ON_ERROR);
            $order->save();
            $this->history($order, $request, 'address_corrected', $order->status, $note, $old, $delivery);

            return $order;
        });
    }

    public function note(Request $request, Order $order, string $note): Order
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return $this->locked($order, function (Order $order) use ($request, $note): Order {
            $this->history($order, $request, 'staff_note', $order->status, $note);

            return $order;
        });
    }

    /** @param array{product_cost: string|null, expense_amount: string, note: string} $data */
    public function finances(Request $request, Order $order, array $data): Order
    {
        Gate::forUser($request->user())->authorize('orders.process');

        return $this->locked($order, function (Order $order) use ($request, $data): Order {
            $before = $order->only(['product_cost', 'expense_amount']);
            $order->product_cost = $data['product_cost'];
            $order->expense_amount = $data['expense_amount'];
            $order->save();
            $this->history($order, $request, 'finances', $order->status, $data['note'], $before, $order->only(['product_cost', 'expense_amount']));

            return $order;
        });
    }

    private function locked(Order $order, \Closure $callback): Order
    {
        return DB::transaction(function () use ($order, $callback): Order {
            $ids = $order->items()->pluck('variant_id');
            $products = ProductVariant::whereIn('id', $ids)->pluck('product_id');
            Product::whereIn('id', $products)->orderBy('id')->lockForUpdate()->get();
            ProductVariant::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            $order = Order::whereKey($order->id)->lockForUpdate()->with('items')->firstOrFail();

            return $callback($order);
        }, 3);
    }

    private function history(Order $order, Request $request, string $action, string $previous, ?string $note = null, ?array $before = null, ?array $after = null): void
    {
        DB::table('order_history')->insert([
            'order_id' => $order->id, 'actor_id' => $request->user()?->id, 'action' => $action,
            'previous_status' => $previous, 'new_status' => $order->status, 'note' => $note,
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => $after === null ? null : json_encode($after, JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
