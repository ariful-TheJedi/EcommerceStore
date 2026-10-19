<?php

namespace App\Catalog\Domain\Services;

use App\Catalog\Models\Category;
use App\Catalog\Models\Product;
use App\Catalog\Models\ProductImage;
use App\Users\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MediaService
{
    public function __construct(private readonly CatalogService $catalog) {}

    public function upload(int $productId, UploadedFile $file, array $data): ProductImage
    {
        $path = 'catalog/'.Str::uuid().'.png';
        try {
            return $this->catalog->transaction(function () use ($productId, $file, $data, $path): ProductImage {
                $product = Product::lockForUpdate()->findOrFail($productId);
                $this->variant($product, $data['variant_id'] ?? null);
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
                if (! Storage::disk(config('catalog.media_disk'))->put($path, $bytes)) {
                    throw new \RuntimeException('Image storage failed.');
                }
                if ($data['is_primary'] ?? false) {
                    $product->images()->where('is_primary', true)->update(['is_primary' => false]);
                }
                $record = new ProductImage;
                $record->fill($data);
                $record->product_id = $product->id;
                $record->image_path = $path;
                $record->save();

                return $record->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk(config('catalog.media_disk'))->delete($path);
            throw $exception;
        }
    }

    public function update(int $productId, int $imageId, array $data): ProductImage
    {
        return $this->catalog->transaction(function () use ($productId, $imageId, $data): ProductImage {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $image = $product->images()->findOrFail($imageId);
            $this->variant($product, $data['variant_id'] ?? $image->variant_id);
            if ($data['is_primary'] ?? false) {
                $product->images()->where('is_primary', true)->update(['is_primary' => false]);
            }
            $image->fill($data)->save();

            return $image;
        });
    }

    public function remove(int $productId, int $imageId): void
    {
        $this->catalog->transaction(function () use ($productId, $imageId): void {
            $product = Product::lockForUpdate()->findOrFail($productId);
            $image = $product->images()->findOrFail($imageId);
            $path = $image->image_path;
            $image->delete();
            DB::afterCommit(fn () => $this->deleteUnreferenced($path));
        });
    }

    public function cleanup(): int
    {
        return $this->catalog->transaction(function (): int {
            $disk = Storage::disk(config('catalog.media_disk'));
            $cutoff = now()->subHours(config('catalog.orphan_grace_hours'))->timestamp;
            $deleted = 0;
            foreach ($disk->allFiles('catalog') as $path) {
                if ($disk->lastModified($path) < $cutoff && $this->deleteUnreferenced($path)) {
                    $deleted++;
                }
            }

            return $deleted;
        });
    }

    private function deleteUnreferenced(string $path): bool
    {
        if (! str_starts_with($path, 'catalog/') || str_contains($path, '..')
            || ProductImage::where('image_path', $path)->exists()
            || Category::where('image_path', $path)->exists()
            || User::where('avatar_path', $path)->exists()) {
            return false;
        }

        return Storage::disk(config('catalog.media_disk'))->delete($path);
    }

    private function variant(Product $product, ?int $variant): void
    {
        if ($variant !== null && ! $product->variants()->whereKey($variant)->exists()) {
            throw ValidationException::withMessages(['variant_id' => 'The variant must belong to this product.']);
        }
    }
}
