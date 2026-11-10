<?php

namespace App\Orders\Models;

use Database\Factories\OrderItemFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[UseFactory(OrderItemFactory::class)]
class OrderItem extends Model
{
    /** @use HasFactory<OrderItemFactory> */
    use HasFactory;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['variant_attributes' => 'array', 'quantity' => 'integer', 'unit_price' => 'decimal:2', 'discount_amount' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Placed order items are immutable. Cancel and recreate the order.'));
        static::deleting(fn () => throw new \LogicException('Order item history must be preserved.'));
    }
}
