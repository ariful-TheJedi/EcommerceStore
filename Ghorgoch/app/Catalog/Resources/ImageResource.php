<?php

namespace App\Catalog\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ImageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'variant_id' => $this->variant_id,
            'url' => str_starts_with($this->image_path, 'productimage/')
                ? asset($this->image_path)
                : Storage::disk(config('catalog.media_disk'))->url($this->image_path),
            'alt_text' => $this->alt_text, 'is_primary' => $this->is_primary, 'sort_order' => $this->sort_order,
        ];
    }
}
