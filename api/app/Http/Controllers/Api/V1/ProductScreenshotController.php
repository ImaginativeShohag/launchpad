<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Product\AddScreenshot;
use App\Actions\Product\RemoveScreenshot;
use App\Actions\Product\ReorderScreenshots;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\OwnedProductRequest;
use App\Http\Requests\Api\V1\ProductScreenshotRequest;
use App\Http\Requests\Api\V1\ReorderScreenshotsRequest;
use App\Http\Resources\Api\V1\ProductScreenshotResource;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;

class ProductScreenshotController extends Controller
{
    public function store(ProductScreenshotRequest $request, Product $product, AddScreenshot $add): JsonResponse
    {
        $file = $request->file('screenshot');
        abort_unless($file instanceof UploadedFile, 422);

        $alt = $request->validated('alt_text');
        $shot = $add->handle($this->authenticated($request), $product, $file, is_string($alt) ? $alt : null);

        return ProductScreenshotResource::make($shot)->response()->setStatusCode(201);
    }

    public function destroy(OwnedProductRequest $request, Product $product, string $screenshot, RemoveScreenshot $remove): Response
    {
        $remove->handle($this->authenticated($request), $product, $screenshot);

        return response()->noContent();
    }

    public function reorder(ReorderScreenshotsRequest $request, Product $product, ReorderScreenshots $reorder): AnonymousResourceCollection
    {
        $updated = $reorder->handle($this->authenticated($request), $product, array_values($request->validated('ids')));

        return ProductScreenshotResource::collection($updated->screenshots()->get());
    }
}
