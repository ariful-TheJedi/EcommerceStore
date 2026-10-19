<?php

namespace App\Catalog\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $public = str_starts_with($request->route()?->getName() ?? '', 'catalog.public.');
        foreach ($this->variants as $variant) {
            $variant->setRelation('product', $this->resource);
        }
        $variantIds = $this->variants->pluck('id')->all();
        $images = $this->images->filter(fn ($image): bool => $image->variant_id === null || in_array($image->variant_id, $variantIds, true));

        return [
            'id' => $this->id, 'name' => $this->name, 'slug' => $this->slug,
            'description' => $this->description, 'status' => $this->status,
            'currency' => config('catalog.currency'),
            'categories' => $this->categories->map(fn ($category): array => ['id' => $category->id, 'name' => $category->name, 'slug' => $category->slug, 'parent_id' => $category->parent_id])->values(),
            'tags' => $this->tags->map(fn ($tag): array => ['id' => $tag->id, 'name' => $tag->name, 'slug' => $tag->slug])->values(),
            'variants' => VariantResource::collection($this->variants)->resolve($request),
            'images' => ImageResource::collection($images->values())->resolve($request),
            'created_at' => $this->created_at,
            ...(! $public ? ['updated_at' => $this->updated_at, 'category_id' => $this->category_id] : []),
        ];
    }
}
