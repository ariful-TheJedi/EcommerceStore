<?php

namespace App\Catalog\Domain\Services;

use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductVariant;
use App\Catalog\Models\Tag;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogService
{
    public function transaction(\Closure $callback): mixed
    {
        try {
            return DB::transaction(function () use ($callback): mixed {
                DB::table('catalog_locks')->where('id', 1)->lockForUpdate()->first();

                return $callback();
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['identifier' => 'The slug, SKU, or option combination already exists.']);
        }
    }

    public function product(array $data, ?int $id = null): Product
    {
        return $this->transaction(function () use ($data, $id): Product {
            $product = $id === null ? new Product : Product::lockForUpdate()->findOrFail($id);
            $categories = $data['category_ids'] ?? null;
            $tags = $data['tag_ids'] ?? null;
            unset($data['category_ids'], $data['tag_ids']);
            if ($categories !== null && ! array_key_exists('category_id', $data)) {
                $data['category_id'] = in_array($product->category_id, $categories) ? $product->category_id : ($categories[0] ?? null);
            }
            if (isset($data['slug']) && Product::where('slug', $data['slug'])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'The slug has already been taken.']);
            }
            $product->fill($data);
            $product->save();
            if ($categories !== null || array_key_exists('category_id', $data)) {
                $ids = $categories ?? $product->categories()->pluck('categories.id')->all();
                if ($product->category_id !== null) {
                    $ids[] = $product->category_id;
                }
                $product->categories()->sync(array_unique($ids));
            }
            if ($tags !== null) {
                $product->tags()->sync($tags);
            }

            return $product;
        });
    }

    public function variant(int $productId, array $data, ?int $id = null): ProductVariant
    {
        return $this->transaction(function () use ($productId, $data, $id): ProductVariant {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $variant = $id === null ? new ProductVariant : $product->variants()->lockForUpdate()->findOrFail($id);
            $variant->product_id = $product->id;
            foreach ($data as $key => $value) {
                if (in_array($key, ['sale_starts_at', 'sale_ends_at'], true) && $value !== null) {
                    $value = Carbon::parse($value)->utc();
                }
                $variant->setAttribute($key, $value);
            }
            $options = ProductVariant::normalizeOptions($variant->attributes ?? []);
            $signature = hash('sha256', json_encode((object) $options, JSON_THROW_ON_ERROR));
            if ($product->variants()->where('option_signature', $signature)->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['attributes' => 'This option combination already exists for the product.']);
            }
            if (ProductVariant::whereRaw('LOWER(sku) = ?', [strtolower($variant->sku)])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['sku' => 'The SKU has already been taken.']);
            }
            if ($variant->sale_price !== null && (float) $variant->sale_price >= (float) $variant->price) {
                throw ValidationException::withMessages(['sale_price' => 'The sale price must be below the regular price.']);
            }
            if ($variant->sale_starts_at && $variant->sale_ends_at && $variant->sale_ends_at->lte($variant->sale_starts_at)) {
                throw ValidationException::withMessages(['sale_ends_at' => 'The sale end must be after its start.']);
            }
            if (! $variant->allow_backorders && $variant->reserved_quantity > $variant->stock_quantity) {
                throw ValidationException::withMessages(['allow_backorders' => 'Reserved stock must be fulfilled before disabling backorders.']);
            }
            $variant->save();

            return $variant;
        });
    }

    public function taxonomy(string $kind, array $data, ?int $id = null): Model
    {
        return $this->transaction(function () use ($kind, $data, $id): Model {
            $class = $kind === 'category' ? Category::class : Tag::class;
            $model = $id === null ? new $class : $class::lockForUpdate()->findOrFail($id);
            if (isset($data['slug']) && $class::where('slug', $data['slug'])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'The slug has already been taken.']);
            }
            if ($kind === 'category' && isset($data['parent_id'])) {
                $parentId = (int) $data['parent_id'];
                $visited = [];
                while ($parentId !== null) {
                    if ($parentId === $id || in_array($parentId, $visited, true)) {
                        throw ValidationException::withMessages(['parent_id' => 'A category cannot be its own parent or descendant.']);
                    }
                    $visited[] = $parentId;
                    $parentId = Category::findOrFail($parentId)->parent_id;
                }
            }
            $model->fill($data);
            $model->save();

            return $model->refresh();
        });
    }

    public function removeTaxonomy(string $kind, int $id): void
    {
        $this->transaction(function () use ($kind, $id): void {
            if ($kind === 'category') {
                $category = Category::lockForUpdate()->findOrFail($id);
                abort_if($category->children()->exists(), 409, 'Move child categories before deleting their parent.');
                $category->products()->detach();
                Product::where('category_id', $id)->update(['category_id' => null]);
                $category->delete();
            } else {
                $tag = Tag::findOrFail($id);
                $tag->products()->detach();
                $tag->delete();
            }
        });
    }
}
