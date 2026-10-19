<?php

namespace App\Catalog\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VariantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $public = str_starts_with($request->route()?->getName() ?? '', 'catalog.public.');
        $product = $this->resource->getRelation('product');
        $images = $product->images->where('variant_id', $this->id);
        if ($images->isEmpty()) {
            $images = $product->images->whereNull('variant_id');
        }

        return [
            'id' => $this->id, 'sku' => $this->sku, 'attributes' => (object) ($this->attributes ?? []),
            'price' => $this->price, 'sale_price' => $this->sale_price,
            'effective_price' => $this->resource->effectivePrice(), 'currency' => config('catalog.currency'),
            'sale_starts_at' => $this->sale_starts_at, 'sale_ends_at' => $this->sale_ends_at,
            'barcode' => $this->barcode, 'weight_grams' => $this->weight_grams, 'is_active' => $this->is_active,
            'available_stock' => $this->resource->availableStock(),
            'available' => $product->status === 'published' && $this->is_active && ($this->allow_backorders || $this->resource->availableStock() > 0),
            'images' => ImageResource::collection($images->values())->resolve($request),
            ...(! $public ? [
                'stock_quantity' => $this->stock_quantity, 'reserved_quantity' => $this->reserved_quantity,
                'allow_backorders' => $this->allow_backorders, 'low_stock_threshold' => $this->low_stock_threshold,
                'cost_price' => $this->cost_price,
            ] : []),
        ];
    }
}
