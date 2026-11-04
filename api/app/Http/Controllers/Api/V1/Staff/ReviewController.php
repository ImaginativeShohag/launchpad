<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Review\DecideReview;
use App\Enums\ReviewDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewDecisionRequest;
use App\Http\Resources\Api\V1\StaffProductResource;
use App\Models\Product;

class ReviewController extends Controller
{
    public function __invoke(ReviewDecisionRequest $request, string $product, DecideReview $decide): StaffProductResource
    {
        $model = Product::query()->findOrFail($product);
        $note = $request->validated('note');

        $decide->handle(
            $this->authenticated($request),
            $model,
            ReviewDecision::from($request->validated('decision')),
            is_string($note) ? $note : null,
            $request->string('submitted_at')->toString(),
        );

        return StaffProductResource::make($model->load(StaffProductController::RELATIONS));
    }
}
