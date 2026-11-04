<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\ProductIntegrity;
use App\Actions\AntiCheat\StaffProductLookup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StaffRiskRequest;
use App\Http\Resources\Api\V1\ProductIntegrityResource;
use App\Models\User;
use Carbon\CarbonImmutable;

class ProductIntegrityController extends Controller
{
    /** Role (403), then the product (404; a draft is the same 404 as `GET staff/products/{id}`). */
    public function __invoke(StaffRiskRequest $request, string $product, StaffProductLookup $lookup, ProductIntegrity $integrity): ProductIntegrityResource
    {
        $caller = $request->user();
        abort_unless($caller instanceof User, 403);

        $model = $lookup->find($caller, $product);

        return ProductIntegrityResource::make($integrity->for($model->id, CarbonImmutable::now('UTC')))->forProduct($model);
    }
}
