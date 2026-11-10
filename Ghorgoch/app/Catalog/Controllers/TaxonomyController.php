<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\CatalogService;
use App\Catalog\Domain\Services\MediaService;
use App\Catalog\Models\Category;
use App\Catalog\Models\Tag;
use App\Catalog\Requests\CatalogRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class TaxonomyController extends Controller
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function publicIndex(): JsonResponse
    {
        $items = Category::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'slug', 'parent_id', 'description', 'image_path']);

        return $this->respond(['items' => $items]);
    }

    public function publicShow(string $slug): JsonResponse
    {
        $item = Category::query()->where('is_active', true)->where('slug', $slug)
            ->firstOrFail(['id', 'name', 'slug', 'parent_id', 'description', 'image_path']);

        return $this->respond(['item' => $item]);
    }

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

    public function uploadCategoryImage(CatalogRequest $request, int $item, MediaService $media): JsonResponse
    {
        return $this->respond(['item' => $media->uploadCategory($item, $request->file('image'))]);
    }

    public function removeCategoryImage(CatalogRequest $request, int $item, MediaService $media): JsonResponse
    {
        return $this->respond(['item' => $media->removeCategoryImage($item)]);
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
