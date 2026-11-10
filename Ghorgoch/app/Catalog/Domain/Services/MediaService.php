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

    public function uploadCategory(int $categoryId, UploadedFile $file): Category
    {
        $bytes = file_get_contents($file->getRealPath());
        $extension = $file->extension();
        if ($extension === 'svg') {
            $document = new \DOMDocument;
            $previous = libxml_use_internal_errors(true);
            try {
                $valid = $document->loadXML($bytes, LIBXML_NONET);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            $allowed = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'defs', 'linearGradient', 'radialGradient', 'stop', 'title', 'desc', 'clipPath', 'mask'];
            if (! $valid || $document->doctype !== null || $document->documentElement?->localName !== 'svg') {
                throw ValidationException::withMessages(['image' => 'Please upload a valid SVG image.']);
            }
            foreach ($document->getElementsByTagName('*') as $element) {
                if (! in_array($element->localName, $allowed, true) || $element->namespaceURI !== 'http://www.w3.org/2000/svg') {
                    throw ValidationException::withMessages(['image' => 'The SVG contains unsupported active content.']);
                }
                foreach ($element->attributes as $attribute) {
                    if (str_starts_with(strtolower($attribute->localName), 'on') || in_array(strtolower($attribute->localName), ['href', 'style', 'base'], true)
                        || (preg_match('/url\s*\(/i', $attribute->value) && ! preg_match('/^url\(#[a-zA-Z0-9_-]+\)$/', $attribute->value))) {
                        throw ValidationException::withMessages(['image' => 'The SVG must not contain scripts or external resources.']);
                    }
                }
            }
            $bytes = $document->saveXML($document->documentElement);
        } else {
            $dimensions = @getimagesize($file->getRealPath());
            if (! $dimensions || config('catalog.upload_max_pixels') < $dimensions[0] * $dimensions[1]) {
                throw ValidationException::withMessages(['image' => 'The image is invalid or exceeds the supported pixel count.']);
            }
        }
        $path = 'catalog/'.Str::uuid().'.'.$extension;
        try {
            return $this->catalog->transaction(function () use ($categoryId, $path, $bytes): Category {
                $category = Category::lockForUpdate()->findOrFail($categoryId);
                $oldPath = $category->image_path;
                if (! Storage::disk(config('catalog.media_disk'))->put($path, $bytes)) {
                    throw new \RuntimeException('Image storage failed.');
                }
                $category->image_path = $path;
                $category->save();
                if ($oldPath) {
                    DB::afterCommit(fn () => $this->deleteUnreferenced($oldPath));
                }

                return $category->refresh();
            });
        } catch (Throwable $exception) {
            Storage::disk(config('catalog.media_disk'))->delete($path);
            throw $exception;
        }
    }

    public function removeCategoryImage(int $categoryId): Category
    {
        return $this->catalog->transaction(function () use ($categoryId): Category {
            $category = Category::lockForUpdate()->findOrFail($categoryId);
            $path = $category->image_path;
            $category->image_path = null;
            $category->save();
            if ($path) {
                DB::afterCommit(fn () => $this->deleteUnreferenced($path));
            }

            return $category->refresh();
        });
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
            || User::where('avatar_path', $path)->exists()
            || in_array($path, app(FrontPageService::class)->mediaPaths(), true)) {
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
