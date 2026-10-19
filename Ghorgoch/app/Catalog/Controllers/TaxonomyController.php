<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\CatalogService;
use App\Catalog\Models\Category;
use App\Catalog\Models\Tag;
use App\Catalog\Requests\CatalogRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TaxonomyController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function index(CatalogRequest $request): JsonResponse
    {
        $class = $this->kind($request) === 'category' ? Category::class : Tag::class;
        $page = $class::orderBy('id')->paginate($request->integer('per_page', 20));

        return $this->respond(['items' => $page->items(), 'pagination' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function show(CatalogRequest $request, int $item): JsonResponse
    {
        $class = $this->kind($request) === 'category' ? Category::class : Tag::class;

        return $this->respond(['item' => $class::findOrFail($item)]);
    }

    public function store(CatalogRequest $request): JsonResponse
    {
        return $this->respond(['item' => $this->catalog->taxonomy($this->kind($request), $request->validated())], 201);
    }

    public function update(CatalogRequest $request, int $item): JsonResponse
    {
        return $this->respond(['item' => $this->catalog->taxonomy($this->kind($request), $request->validated(), $item)]);
    }

    public function destroy(CatalogRequest $request, int $item): JsonResponse
    {
        $this->catalog->removeTaxonomy($this->kind($request), $item);

        return $this->respond([]);
    }

    private function kind(CatalogRequest $request): string
    {
        return $request->route()->defaults['catalog_kind'];
    }

    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
