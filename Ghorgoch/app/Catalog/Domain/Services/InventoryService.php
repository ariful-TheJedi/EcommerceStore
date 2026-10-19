<?php

namespace App\Catalog\Domain\Services;

use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Users\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InventoryService
{
    public function adjust(User $actor, int $variantId, int $change, string $reason, string $key, ?int $orderId = null): ProductVariant
    {
        Gate::forUser($actor)->authorize('inventory.adjust');

        return $this->locked($variantId, function (ProductVariant $variant) use ($actor, $change, $reason, $key, $orderId): ProductVariant {
            $existing = DB::table('inventory_transactions')->where('operation_key', $key)->first();
            if ($existing) {
                abort_if($existing->variant_id !== $variant->id || $existing->quantity_change !== $change || $existing->note !== $reason || $existing->order_id !== $orderId, 409, 'An operation key cannot be reused for different adjustments.');

                return $variant;
            }
            if ($change === 0 || trim($reason) === '' || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'A nonzero adjustment and a reason are required.']);
            }
            $onHand = $variant->stock_quantity + $change;
            if ($onHand < 0 || (! $variant->allow_backorders && $onHand < $variant->reserved_quantity)) {
                throw ValidationException::withMessages(['quantity_change' => 'The adjustment would produce negative available stock.']);
            }
            $variant->stock_quantity = $onHand;
            $variant->save();
            $this->ledger($actor, $variant, 'adjustment', $change, 0, $reason, $key, $orderId);

            return $variant;
        });
    }

    public function reserve(User $actor, int $variantId, int $orderId, int $quantity): object
    {
        Gate::forUser($actor)->authorize('orders.process');

        return $this->locked($variantId, function (ProductVariant $variant) use ($actor, $orderId, $quantity): object {
            $existing = DB::table('stock_reservations')->where('variant_id', $variant->id)->where('order_id', $orderId)->lockForUpdate()->first();
            if ($existing) {
                abort_if($existing->quantity !== $quantity || $existing->status === 'released', 409, 'This reservation is already closed or has a different quantity.');

                return $existing;
            }
            $this->purchasable($variant, $orderId);
            if ($quantity < 1 || (! $variant->allow_backorders && $variant->availableStock() < $quantity)) {
                throw ValidationException::withMessages(['quantity' => 'Insufficient available stock.']);
            }
            $variant->reserved_quantity += $quantity;
            $variant->save();
            $id = DB::table('stock_reservations')->insertGetId([
                'variant_id' => $variant->id, 'order_id' => $orderId, 'quantity' => $quantity,
                'status' => 'reserved', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->ledger($actor, $variant, 'reserve', 0, $quantity, 'Order stock reservation', 'reserve-'.$id, $orderId);

            return DB::table('stock_reservations')->find($id);
        });
    }

    public function finish(User $actor, int $variantId, int $orderId, string $action): object
    {
        Gate::forUser($actor)->authorize('orders.process');
        abort_unless(in_array($action, ['release', 'deduct'], true), 422);

        return $this->locked($variantId, function (ProductVariant $variant) use ($actor, $orderId, $action): object {
            $reservation = DB::table('stock_reservations')->where('variant_id', $variant->id)->where('order_id', $orderId)->lockForUpdate()->first();
            abort_unless($reservation, 404);
            $status = $action === 'release' ? 'released' : 'deducted';
            if ($reservation->status === $status) {
                return $reservation;
            }
            abort_if($reservation->status !== 'reserved', 409, 'The reservation is already closed.');
            $change = 0;
            if ($action === 'deduct') {
                $this->purchasable($variant, $orderId);
                if ($variant->stock_quantity < $reservation->quantity) {
                    throw ValidationException::withMessages(['quantity' => 'Backordered stock must be received before deduction.']);
                }
                $change = -$reservation->quantity;
                $variant->stock_quantity += $change;
            }
            $variant->reserved_quantity -= $reservation->quantity;
            $variant->save();
            DB::table('stock_reservations')->where('id', $reservation->id)->update(['status' => $status, 'updated_at' => now()]);
            $this->ledger($actor, $variant, $action, $change, -$reservation->quantity, 'Order stock '.$action, $action.'-'.$reservation->id, $orderId);

            return DB::table('stock_reservations')->find($reservation->id);
        });
    }

    private function purchasable(ProductVariant $variant, int $orderId): void
    {
        $order = DB::table('orders')->where('id', $orderId)->lockForUpdate()->first();
        abort_unless($order, 404);
        if ($variant->product->status !== 'published' || ! $variant->is_active || in_array($order->status, ['cancelled', 'returned'], true)) {
            throw ValidationException::withMessages(['variant' => 'This item or order is not eligible for purchase.']);
        }
    }

    private function locked(int $id, \Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($id, $callback): mixed {
                $productId = ProductVariant::whereKey($id)->value('product_id');
                abort_unless($productId, 404);
                $product = Product::lockForUpdate()->findOrFail($productId);
                $variant = $product->variants()->lockForUpdate()->findOrFail($id);
                $variant->setRelation('product', $product);

                return $callback($variant);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'The operation key has already been used.');
        }
    }

    private function ledger(User $actor, ProductVariant $variant, string $type, int $change, int $reserved, string $reason, string $key, ?int $orderId = null): void
    {
        DB::table('inventory_transactions')->insert([
            'variant_id' => $variant->id, 'order_id' => $orderId, 'actor_id' => $actor->id,
            'transaction_type' => $type, 'quantity_change' => $change, 'reserved_change' => $reserved,
            'note' => $reason, 'operation_key' => $key, 'created_at' => now(),
        ]);
    }
}
