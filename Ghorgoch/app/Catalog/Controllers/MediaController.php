<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\MediaService;
use App\Catalog\Requests\CatalogRequest;
use App\Catalog\Resources\ImageResource;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class MediaController extends Controller
{
    public function __construct(private readonly MediaService $media) {}

    public function upload(CatalogRequest $request, int $product): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);
        $image = $this->media->upload($product, $request->file('image'), $data);

        return response()->json(['message' => 'Success.', 'data' => ['image' => (new ImageResource($image))->resolve($request)]], 201)->header('Cache-Control', 'no-store, private');
    }

    public function updateImage(CatalogRequest $request, int $product, int $image): JsonResponse
    {
        $record = $this->media->update($product, $image, $request->validated());

        return response()->json(['message' => 'Success.', 'data' => ['image' => (new ImageResource($record))->resolve($request)]])->header('Cache-Control', 'no-store, private');
    }

    public function destroy(CatalogRequest $request, int $product, int $image): JsonResponse
    {
        $this->media->remove($product, $image);

        return response()->json(['message' => 'Success.', 'data' => []])->header('Cache-Control', 'no-store, private');
    }
}
