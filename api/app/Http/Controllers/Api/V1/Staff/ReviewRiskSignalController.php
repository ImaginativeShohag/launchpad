<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\ReviewRiskSignal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReviewRiskSignalRequest;
use App\Http\Resources\Api\V1\RiskSignalResource;
use App\Models\RiskSignal;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

class ReviewRiskSignalController extends Controller
{
    /** The id is a plain string: the request judges the role first, only then is the signal looked up (a malformed id is a 404). */
    public function __invoke(ReviewRiskSignalRequest $request, string $signal, ReviewRiskSignal $review): RiskSignalResource
    {
        if (! Str::isUlid($signal)) {
            throw (new ModelNotFoundException)->setModel(RiskSignal::class);
        }

        $result = $review->handle($this->authenticated($request), $signal, $request->outcome(), $request->note());

        return RiskSignalResource::make($result['signal']->load(['user', 'product', 'reviewer']))->additional(['meta' => ['changed' => $result['changed']]]);
    }
}
