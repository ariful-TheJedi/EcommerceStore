<?php

namespace App\Catalog\Domain\Services;

use App\Catalog\Models\Category;
use App\Catalog\Models\FrontPageSetting;
use App\Catalog\Resources\ProductResource;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FrontPageService
{
    public function __construct(private readonly CatalogQuery $query, private readonly CatalogService $catalog) {}

    /** @return array{content: ?array<string, mixed>, version: int} */
    public function settings(): array
    {
        $settings = FrontPageSetting::findOrFail(1);

        return ['content' => $settings->content, 'version' => $settings->version];
    }

    /** @param array<string, mixed> $content
     * @return array{content: ?array<string, mixed>, version: int}
     */
    public function save(array $content, int $version): array
    {
        return $this->catalog->transaction(function () use ($content, $version): array {
            $settings = FrontPageSetting::lockForUpdate()->findOrFail(1);
            abort_unless($settings->version === $version, 409, 'The front page was changed by another administrator. Reload before saving.');
            $settings->fill(['content' => $content, 'version' => $version + 1])->save();

            return ['content' => $settings->content, 'version' => $settings->version];
        });
    }

    /** @return array<string, mixed> */
    public function publicPage(Request $request): array
    {
        $settings = $this->settings();
        $content = $settings['content'];
        if ($content === null) {
            return ['settings' => $settings, 'categories' => [], 'featured' => [], 'new_arrivals' => []];
        }
        foreach (['hero' => 'slides', 'promotions' => 'cards'] as $section => $group) {
            foreach ($content[$section][$group] as &$item) {
                if (! empty($item['image_path'])) {
                    $item['image'] = Storage::disk(config('catalog.media_disk'))->url($item['image_path']);
                }
            }
            unset($item);
        }
        $categoryIds = $content['categories']['ids'];
        $categories = $content['categories']['visible'] ? Category::where('is_active', true)->whereIn('id', $categoryIds)->get()->keyBy('id') : collect();
        $featured = $this->selectedProducts($content['featured']['visible'] ? $content['featured']['ids'] : [], $request);
        $newest = [];
        if ($content['new_arrivals']['visible']) {
            $newest = $content['new_arrivals']['mode'] === 'selected'
                ? array_slice($this->selectedProducts($content['new_arrivals']['ids'], $request), 0, $content['new_arrivals']['limit'])
                : ProductResource::collection($this->query->products(['sort' => '-created_at'], true)->limit($content['new_arrivals']['limit'])->get())->resolve($request);
        }

        return [
            'settings' => ['content' => $content, 'version' => $settings['version']],
            'categories' => collect($categoryIds)->map(fn (int $id) => $categories->get($id))->filter()->values()->all(),
            'featured' => $featured, 'new_arrivals' => $newest,
        ];
    }

    /** @param array<int, int> $ids
     * @return array<int, array<string, mixed>>
     */
    private function selectedProducts(array $ids, Request $request): array
    {
        if ($ids === []) {
            return [];
        }
        $products = $this->query->products([], true)->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)->map(fn (int $id) => $products->has($id) ? (new ProductResource($products->get($id)))->resolve($request) : null)->filter()->values()->all();
    }

    /** @return array{image_path: string, url: string} */
    public function upload(UploadedFile $file): array
    {
        $dimensions = @getimagesize($file->getRealPath());
        if (! $dimensions || config('catalog.upload_max_pixels') < $dimensions[0] * $dimensions[1]) {
            throw ValidationException::withMessages(['image' => 'The image is invalid or exceeds the supported pixel count.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'The file could not be decoded as an image.']);
        }
        imagesavealpha($image, true);
        ob_start();
        try {
            imagepng($image);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        $path = 'catalog/'.Str::uuid().'.png';
        $disk = Storage::disk(config('catalog.media_disk'));
        if (! $disk->put($path, $bytes)) {
            throw new \RuntimeException('Image storage failed.');
        }

        return ['image_path' => $path, 'url' => $disk->url($path)];
    }

    /** @return array<int, string> */
    public function mediaPaths(): array
    {
        $content = $this->settings()['content'];

        return array_values(array_filter(array_merge(array_column($content['hero']['slides'] ?? [], 'image_path'), array_column($content['promotions']['cards'] ?? [], 'image_path'))));
    }
}
