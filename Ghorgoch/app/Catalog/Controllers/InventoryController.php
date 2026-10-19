<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\CatalogQuery;
use App\Catalog\Domain\Services\InventoryService;
use App\Catalog\Models\ProductVariant;
use App\Catalog\Requests\CatalogRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function stock(CatalogRequest $request): JsonResponse
    {
        $query = ProductVariant::with('product:id,name,status')->orderBy('id');
        if ($request->has('availability')) {
            CatalogQuery::availability($query, $request->string('availability')->toString());
        }
        $page = $query->paginate($request->integer('per_page', 20));
        $variants = $page->getCollection()->map(fn (ProductVariant $variant): array => [
            'id' => $variant->id, 'product_id' => $variant->product_id, 'sku' => $variant->sku,
            'stock_quantity' => $variant->stock_quantity, 'reserved_quantity' => $variant->reserved_quantity,
            'available_stock' => $variant->availableStock(), 'allow_backorders' => $variant->allow_backorders,
            'is_active' => $variant->is_active,
        ]);

        return $this->respond(['variants' => $variants, 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function adjust(CatalogRequest $request, int $variant): JsonResponse
    {
        $data = $request->validated();
        $item = $this->inventory->adjust($request->user(), $variant, $data['quantity_change'], $data['reason'], $data['operation_key'], $data['order_id'] ?? null);

        return $this->respond(['variant' => ['id' => $item->id, 'stock_quantity' => $item->stock_quantity, 'reserved_quantity' => $item->reserved_quantity, 'available_stock' => $item->availableStock()]]);
    }

    public function reserve(CatalogRequest $request, int $variant): JsonResponse
    {
        return $this->respond(['reservation' => $this->inventory->reserve($request->user(), $variant, $request->integer('order_id'), $request->integer('quantity'))]);
    }

    public function release(CatalogRequest $request, int $variant): JsonResponse
    {
        return $this->respond(['reservation' => $this->inventory->finish($request->user(), $variant, $request->integer('order_id'), 'release')]);
    }

    public function deduct(CatalogRequest $request, int $variant): JsonResponse
    {
        return $this->respond(['reservation' => $this->inventory->finish($request->user(), $variant, $request->integer('order_id'), 'deduct')]);
    }

    public function history(CatalogRequest $request, int $variant): JsonResponse
    {
        ProductVariant::findOrFail($variant);
        $page = DB::table('inventory_transactions')->where('variant_id', $variant)->orderByDesc('id')->paginate($request->integer('per_page', 20));

        return $this->respond(['transactions' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    private function respond(array $data): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data])->header('Cache-Control', 'no-store, private');
    }
}
