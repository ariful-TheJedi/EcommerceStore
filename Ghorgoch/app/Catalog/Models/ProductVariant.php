<?php

namespace App\Catalog\Models;

use App\Orders\Models\CartItem;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

#[Fillable(['product_id', 'sku', 'attributes', 'price', 'compare_at_price', 'stock_quantity', 'is_active'])]
#[UseFactory(ProductVariantFactory::class)]
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $hidden = ['cost_price', 'option_signature'];

    protected static function booted(): void
    {
        static::saving(function (ProductVariant $variant): void {
            $options = self::normalizeOptions($variant->getAttribute('attributes') ?? []);
            $variant->setAttribute('attributes', $options);
            $variant->option_signature = hash('sha256', json_encode((object) $options, JSON_THROW_ON_ERROR));
        });
    }

    public static function normalizeOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $key => $value) {
            $name = mb_strtolower(trim((string) $key));
            if (mb_strlen($name) > 40 || ! preg_match('/^[a-z][a-z0-9_ -]*$/', $name)
                || ! is_string($value) || mb_strlen($value) > 100) {
                throw ValidationException::withMessages(['attributes' => 'Options require valid names and string values of at most 100 characters.']);
            }
            if (array_key_exists($name, $normalized)) {
                throw ValidationException::withMessages(['attributes' => 'Option names must be unique.']);
            }
            $normalized[$name] = mb_strtolower(trim($value));
            if ($normalized[$name] === '') {
                throw ValidationException::withMessages(['attributes' => 'Option values cannot be empty.']);
            }
        }
        ksort($normalized);

        return $normalized;
    }

    public function setAttributesAttribute(array $value): void
    {
        $this->attributes['attributes'] = json_encode((object) $value, JSON_THROW_ON_ERROR);
    }

    public function effectivePrice(): string
    {
        $onSale = $this->sale_price !== null
            && ($this->sale_starts_at === null || $this->sale_starts_at->lte(now()))
            && ($this->sale_ends_at === null || $this->sale_ends_at->gt(now()));

        return $onSale ? $this->sale_price : $this->price;
    }

    public function availableStock(): int
    {
        return $this->stock_quantity - $this->reserved_quantity;
    }

    protected function casts(): array
    {
        return ['attributes' => 'array', 'price' => 'decimal:2', 'compare_at_price' => 'decimal:2', 'sale_price' => 'decimal:2', 'cost_price' => 'decimal:2', 'sale_starts_at' => 'datetime', 'sale_ends_at' => 'datetime', 'stock_quantity' => 'integer', 'reserved_quantity' => 'integer', 'low_stock_threshold' => 'integer', 'allow_backorders' => 'boolean', 'is_active' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function images(): HasMany
    {
        return $this->hasMany(ProductImage::class, 'variant_id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'variant_id');
    }
}
