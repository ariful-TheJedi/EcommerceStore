<?php

namespace App\Orders\Models;

use App\Customers\Models\Customer;
use App\Orders\Domain\Services\OrderCustomerService;
use App\Users\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[UseFactory(OrderFactory::class)]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    use SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            if ($order->customer_id !== null) {
                app(OrderCustomerService::class)->assertCanOrder(Customer::with('user')->findOrFail($order->customer_id));
            }
        });
        static::updating(function (Order $order): void {
            if ($order->getOriginal('checkout_key') !== null && $order->isDirty([
                'customer_id', 'order_number', 'checkout_key', 'checkout_fingerprint', 'contact_snapshot',
                'subtotal', 'discount_amount', 'shipping_fee', 'tax_amount', 'total_amount', 'currency', 'payment_method',
            ])) {
                throw new \LogicException('Placed order identity, prices, and totals are immutable. Cancel and recreate the order.');
            }
        });
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    protected function casts(): array
    {
        return ['product_cost' => 'decimal:2', 'expense_amount' => 'decimal:2', 'returned_at' => 'datetime', 'return_restocked' => 'boolean', 'contact_snapshot' => 'array', 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'shipping_fee' => 'decimal:2', 'tax_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'confirmed_at' => 'datetime', 'processing_at' => 'datetime', 'shipped_at' => 'datetime', 'delivered_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
