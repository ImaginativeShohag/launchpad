<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Views\RecordView;
use App\Actions\Views\ViewSignals;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductViewRequest;
use App\Support\OptionalViewer;
use Illuminate\Http\Response;

class ProductViewController extends Controller
{
    // `$product` is a plain string on purpose: the action decides visibility, so every hidden case is one 404.
    public function __invoke(ProductViewRequest $request, string $product, RecordView $record): Response
    {
        $referrer = $request->validated('referrer');

        $record->handle($product, OptionalViewer::from($request), ViewSignals::fromRequest($request, is_string($referrer) ? $referrer : null));

        return response()->noContent();
    }
}
