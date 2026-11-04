<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\Review\StaffProductList;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffProductListRequest;
use App\Http\Requests\Api\V1\StaffProductRequest;
use App\Http\Resources\Api\V1\StaffProductResource;
use App\Http\Resources\Api\V1\StaffProductRowResource;
use App\Models\Product;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class StaffProductController extends Controller
{
    public const RELATIONS = ['maker', 'topics', 'links', 'screenshots', 'reviews.reviewer'];

    /** Every non-draft product, latest change first. The request judges the role before anything is validated. */
    public function index(StaffProductListRequest $request, StaffProductList $list): AnonymousResourceCollection
    {
        $page = $list->page($request->status(), $request->search(), $request->perPage())
            ->appends(array_filter([
                'status' => $request->validated('status'), 'q' => $request->validated('q'), 'per_page' => $request->validated('per_page'),
            ], fn ($value) => $value !== null));

        return StaffProductRowResource::collection($page);
    }

    /** The id is a plain string on purpose: the staff check in the request runs before any lookup. */
    public function show(StaffProductRequest $request, string $product): StaffProductResource
    {
        $model = Product::query()->findOrFail($product);
        Gate::authorize('viewStaff', $model);

        return StaffProductResource::make($model->load(self::RELATIONS));
    }
}
