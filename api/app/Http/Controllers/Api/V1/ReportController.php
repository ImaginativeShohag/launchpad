<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Report\CreateReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateReportRequest;
use App\Http\Resources\Api\V1\ReportResource;
use Illuminate\Http\JsonResponse;

class ReportController extends Controller
{
    // `$comment` and `$product` are plain strings on purpose: the action decides reportability, so every hidden case is one 404.
    public function comment(CreateReportRequest $request, string $comment, CreateReport $create): JsonResponse
    {
        return $this->respond($request, $create, 'comment', $comment);
    }

    public function product(CreateReportRequest $request, string $product, CreateReport $create): JsonResponse
    {
        return $this->respond($request, $create, 'product', $product);
    }

    /** @param  'comment'|'product'  $type */
    private function respond(CreateReportRequest $request, CreateReport $create, string $type, string $targetId): JsonResponse
    {
        $result = $create->handle($this->authenticated($request), $type, $targetId, $request->reason(), $request->details());

        // 201 for a new report, 200 for the member's own existing open report (nothing new was written).
        return ReportResource::make($result['report'])->response()->setStatusCode($result['created'] ? 201 : 200);
    }
}
