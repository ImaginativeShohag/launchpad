<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\ProductVoteQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductVotesRequest;
use App\Http\Resources\Api\V1\StaffVoteResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ProductVotesController extends Controller
{
    /** Staff-only list of a product's votes with the voters. The request already judged the role and found the product. */
    public function __invoke(ProductVotesRequest $request, ProductVoteQuery $votes): AnonymousResourceCollection
    {
        $page = $votes->page($request->product(), $request->status(), $request->voter(), $request->perPage())
            ->appends(array_filter(['per_page' => $request->perPage(), 'status' => $request->status(), 'user' => $request->voter()], fn ($v) => $v !== null));

        return StaffVoteResource::collection($page);
    }
}
