<?php

namespace App\Orders\Domain\Services;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Orders\Models\Cart;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function resolve(Request $request): Cart
    {
        if ($request->user() !== null) {
            $customer = $request->user()->customer()->firstOrFail();
            $cart = Cart::firstOrCreate(['customer_id' => $customer->id]);
        } else {
            $token = $request->session()->get('guest_cart_token');
            if (! is_string($token)) {
                $token = Str::random(64);
                $request->session()->put('guest_cart_token', $token);
            }
            $cart = Cart::firstOrCreate(['guest_key' => hash('sha256', $token)]);
        }

        return $cart;
    }

    public function locked(Cart $cart, \Closure $callback): mixed
    {
        return DB::transaction(function () use ($cart, $callback): mixed {
            $cart = Cart::whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->expires_at === null || $cart->expires_at->isPast()) {
                if ($cart->expires_at !== null) {
                    $cart->items()->delete();
                }
                $cart->expires_at = now()->addDays(config('orders.cart_days'));
                $cart->save();
            }

            return $callback($cart);
        }, 3);
    }

    public function change(Cart $cart, int $variantId, int $quantity, bool $add): array
    {
        return $this->locked($cart, function (Cart $cart) use ($variantId, $quantity, $add): array {
            $line = $cart->items()->where('variant_id', $variantId)->first();
            if (! $add && $line === null) {
                abort(404);
            }
            $quantity = $add ? ($line?->quantity ?? 0) + $quantity : $quantity;
            $this->quantity($quantity);
            $variant = ProductVariant::with('product')->findOrFail($variantId);
            $this->purchasable($variant, $quantity);
            if ($line === null && $cart->items()->count() >= config('orders.max_lines')) {
                throw ValidationException::withMessages(['variant_id' => 'The cart has too many distinct items.']);
            }
            $cart->items()->updateOrCreate(['variant_id' => $variantId], ['quantity' => $quantity]);
            $cart->converted_at = null;
            $cart->expires_at = now()->addDays(config('orders.cart_days'));
            $cart->save();

            return $this->summary($cart);
        });
    }

    public function remove(Cart $cart, int $variantId): array
    {
        return $this->locked($cart, function (Cart $cart) use ($variantId): array {
            $cart->items()->where('variant_id', $variantId)->firstOrFail()->delete();

            return $this->summary($cart);
        });
    }

    public function summary(Cart $cart, bool $strict = false, bool $lock = false): array
    {
        $lines = $cart->items()->orderBy('variant_id')->get();
        if ($strict && $lines->count() > config('orders.max_lines')) {
            throw ValidationException::withMessages(['cart' => 'The cart has too many distinct items.']);
        }
        $ids = $lines->pluck('variant_id');
        if ($lock) {
            $productIds = ProductVariant::whereIn('id', $ids)->pluck('product_id');
            Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
        }
        $query = ProductVariant::with('product')->whereIn('id', $ids)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $variants = $query->get()->keyBy('id');
        $items = [];
        $subtotal = 0;
        $valid = true;
        foreach ($lines as $line) {
            $variant = $variants->get($line->variant_id);
            $available = $variant !== null && $variant->product->status === 'published' && $variant->is_active && (! config('catalog.inventory_enabled') || $variant->availableStock() >= $line->quantity);
            if ($strict) {
                abort_unless($variant, 422);
                $this->purchasable($variant, $line->quantity);
            }
            $unit = $variant !== null ? Money::minor($variant->effectivePrice()) : 0;
            $lineTotal = $unit * $line->quantity;
            $subtotal += $lineTotal;
            $valid = $valid && $available;
            $items[] = [
                'variant_id' => $line->variant_id, 'product_id' => $variant?->product_id,
                'name' => $variant?->product->name, 'sku' => $variant?->sku,
                'attributes' => $variant?->attributes ?? [], 'quantity' => $line->quantity,
                'unit_price' => Money::decimal($unit), 'line_total' => Money::decimal($lineTotal),
                'discount_amount' => '0.00', 'available' => $available,
            ];
        }
        if ($strict && $items === []) {
            throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
        }

        return ['items' => $items, 'subtotal' => Money::decimal($subtotal), 'discount_amount' => '0.00',
            'estimated_total' => Money::decimal($subtotal), 'currency' => config('orders.currency'),
            'can_checkout' => $valid && $items !== [], 'expires_at' => $cart->expires_at?->toISOString()];
    }

    /** @param list<array{variant_id: int, quantity: int}> $items */
    public function sync(Cart $cart, array $items): array
    {
        return $this->locked($cart, function (Cart $cart) use ($items): array {
            $ids = array_column($items, 'variant_id');
            $productIds = ProductVariant::whereIn('id', $ids)->pluck('product_id');
            Product::whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get();
            $variants = ProductVariant::with('product')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($items as $item) {
                $variant = $variants->get($item['variant_id']);
                if ($variant === null) {
                    throw ValidationException::withMessages(['items' => 'A selected product is no longer available.']);
                }
                $this->purchasable($variant, $item['quantity']);
            }
            $cart->items()->delete();
            foreach ($items as $item) {
                $cart->items()->create($item);
            }
            $cart->converted_at = null;
            $cart->expires_at = now()->addDays(config('orders.cart_days'));
            $cart->save();

            return $this->summary($cart);
        });
    }

    public function merge(Request $request): array
    {
        $target = $this->resolve($request);

        return $this->locked($target, function (Cart $target) use ($request): array {
            $token = $request->session()->get('guest_cart_token');
            $guest = is_string($token) ? Cart::where('guest_key', hash('sha256', $token))->whereNull('customer_id')->lockForUpdate()->first() : null;
            if ($guest === null || $guest->converted_at !== null || $guest->expires_at?->isPast()) {
                $request->session()->forget('guest_cart_token');

                return $this->summary($target);
            }
            foreach ($guest->items()->orderBy('variant_id')->get() as $line) {
                $this->change($target, $line->variant_id, $line->quantity, true);
            }
            $guest->items()->delete();
            $guest->converted_at = now();
            $guest->save();
            $request->session()->forget('guest_cart_token');

            return $this->summary($target);
        });
    }

    public function purchasable(ProductVariant $variant, int $quantity): void
    {
        $this->quantity($quantity);
        if ($variant->product->status !== 'published' || ! $variant->is_active || (config('catalog.inventory_enabled') && $variant->availableStock() < $quantity)) {
            throw ValidationException::withMessages(['variant_id' => 'This item is unavailable or has insufficient stock.']);
        }
    }

    private function quantity(int $quantity): void
    {
        if ($quantity < 1 || $quantity > config('orders.max_quantity')) {
            throw ValidationException::withMessages(['quantity' => 'Quantity is outside the permitted range.']);
        }
    }
}
