<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\CatalogQuery;
use App\Catalog\Domain\Services\CatalogService;
use App\Catalog\Requests\CatalogRequest;
use App\Catalog\Resources\ProductResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(private readonly CatalogService $catalog, private readonly CatalogQuery $query) {}

    public function index(CatalogRequest $request): JsonResponse
    {
        return $this->listing($request, false);
    }

    public function publicIndex(CatalogRequest $request): JsonResponse
    {
        return $this->listing($request, true);
    }

    public function show(CatalogRequest $request, int $product): JsonResponse
    {
        return $this->account($request, $product);
    }

    public function publicShow(CatalogRequest $request, string $slug): JsonResponse
    {
        $product = $this->query->products([], true)->where('slug', $slug)->firstOrFail();

        return $this->respond(['product' => (new ProductResource($product))->resolve($request)]);
    }

    public function store(CatalogRequest $request): JsonResponse
    {
        $product = $this->catalog->product($request->validated());

        return $this->account($request, $product->id, 201);
    }

    public function update(CatalogRequest $request, int $product): JsonResponse
    {
        $this->catalog->product($request->validated(), $product);

        return $this->account($request, $product);
    }

    public function archive(CatalogRequest $request, int $product): JsonResponse
    {
        $this->catalog->product(['status' => 'archived'], $product);

        return $this->account($request, $product);
    }

    public function storeVariant(CatalogRequest $request, int $product): JsonResponse
    {
        $this->catalog->variant($product, $request->validated());

        return $this->account($request, $product, 201);
    }

    public function updateVariant(CatalogRequest $request, int $product, int $variant): JsonResponse
    {
        $this->catalog->variant($product, $request->validated(), $variant);

        return $this->account($request, $product);
    }

    public function disableVariant(CatalogRequest $request, int $product, int $variant): JsonResponse
    {
        $this->catalog->variant($product, ['is_active' => false], $variant);

        return $this->account($request, $product);
    }

    private function listing(CatalogRequest $request, bool $public): JsonResponse
    {
        $page = $this->query->products($request->validated(), $public)->paginate($request->integer('per_page', 20));

        return $this->respond([
            'products' => ProductResource::collection($page->getCollection())->resolve($request),
            'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    private function account(CatalogRequest $request, int $id, int $status = 200): JsonResponse
    {
        $product = $this->query->products([], false)->findOrFail($id);

        return $this->respond(['product' => (new ProductResource($product))->resolve($request)], $status);
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
