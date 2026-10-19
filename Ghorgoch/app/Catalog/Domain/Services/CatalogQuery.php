<?php

namespace App\Catalog\Domain\Services;

use App\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

class CatalogQuery
{
    public function products(array $filters, bool $public): Builder
    {
        $query = Product::query()->with([
            'variants' => function (Relation $query) use ($public): void {
                if ($public) {
                    $query->where('is_active', true);
                }
                $query->orderBy('id');
            },
            'images' => fn (Relation $query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            'categories' => function (Relation $query) use ($public): void {
                if ($public) {
                    $query->where('is_active', true);
                }
            },
            'tags',
        ]);
        $query->when($public, fn (Builder $query) => $query->where('status', 'published'));
        if (! $public && isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['search'])) {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(fn (Builder $query) => $query->whereRaw('LOWER(name) LIKE ?', [$search])->orWhereRaw('LOWER(description) LIKE ?', [$search]));
        }
        foreach (['category' => 'categories', 'tag' => 'tags'] as $filter => $relation) {
            if (isset($filters[$filter])) {
                $query->whereHas($relation, fn (Builder $query) => $query->where('slug', $filters[$filter])->when($public && $filter === 'category', fn (Builder $query) => $query->where('is_active', true)));
            }
        }
        $effective = self::priceSql();
        $now = now();
        $availability = $filters['availability'] ?? null;
        if (isset($filters['min_price']) || isset($filters['max_price']) || in_array($availability, ['in_stock', 'low_stock'], true)) {
            $query->whereHas('variants', function (Builder $query) use ($filters, $effective, $now, $public): void {
                $query->when($public, fn (Builder $query) => $query->where('is_active', true));
                if (isset($filters['min_price'])) {
                    $query->whereRaw($effective.' >= ?', [$now, $now, $filters['min_price']]);
                }
                if (isset($filters['max_price'])) {
                    $query->whereRaw($effective.' <= ?', [$now, $now, $filters['max_price']]);
                }
                if (isset($filters['availability']) && $filters['availability'] !== 'out_of_stock') {
                    $query->where('is_active', true);
                    self::availability($query, $filters['availability']);
                }
            });
        }
        if ($availability === 'out_of_stock') {
            $query->whereDoesntHave('variants', function (Builder $query): void {
                $query->where('is_active', true);
                self::availability($query, 'in_stock');
            });
        }
        $sort = $filters['sort'] ?? 'id';
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        if ($field === 'price') {
            $query->orderByRaw('(SELECT MIN('.$effective.') FROM product_variants WHERE product_variants.product_id = products.id'.($public ? ' AND is_active = TRUE' : '').') '.$direction, [$now, $now]);
        } else {
            $query->orderBy($field, $direction);
        }
        if ($field !== 'id') {
            $query->orderBy('id');
        }

        return $query;
    }

    public static function availability(Builder $query, string $filter): void
    {
        match ($filter) {
            'in_stock' => $query->where(fn (Builder $query) => $query->whereColumn('stock_quantity', '>', 'reserved_quantity')->orWhere('allow_backorders', true)),
            'out_of_stock' => $query->where('allow_backorders', false)->whereColumn('stock_quantity', '<=', 'reserved_quantity'),
            'low_stock' => $query->whereRaw('(stock_quantity - reserved_quantity) > 0')->whereRaw('(stock_quantity - reserved_quantity) <= low_stock_threshold'),
        };
    }

    private static function priceSql(): string
    {
        return 'CAST((CASE WHEN sale_price IS NOT NULL AND (sale_starts_at IS NULL OR sale_starts_at <= ?) AND (sale_ends_at IS NULL OR sale_ends_at > ?) THEN sale_price ELSE price END) AS DECIMAL(12,2))';
    }
}
