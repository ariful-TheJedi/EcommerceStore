<?php

namespace App\Catalog\Controllers;

use App\Catalog\Domain\Services\FrontPageService;
use App\Catalog\Models\Product;
use App\Catalog\Requests\FrontPageRequest;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FrontPageController extends Controller
{
    public function __construct(private readonly FrontPageService $frontPage) {}

    public function show(): JsonResponse
    {
        $settings = $this->frontPage->settings();
        $content = $settings['content'];
        $ids = array_merge($content['featured']['ids'] ?? [], $content['new_arrivals']['ids'] ?? []);

        return $this->respond([...$settings, 'products' => Product::whereIn('id', $ids)->get(['id', 'name', 'status'])->all()]);
    }

    public function publicShow(Request $request): JsonResponse
    {
        return $this->respond($this->frontPage->publicPage($request));
    }

    public function update(FrontPageRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->respond($this->frontPage->save($data['content'], $data['version']));
    }

    public function upload(FrontPageRequest $request): JsonResponse
    {
        return $this->respond($this->frontPage->upload($request->file('image')), 201);
    }

    /** @param array<string, mixed> $data */
    private function respond(array $data, int $status = 200): JsonResponse
    {
        return response()->json(['message' => 'Success.', 'data' => $data], $status)->header('Cache-Control', 'no-store, private');
    }
}
