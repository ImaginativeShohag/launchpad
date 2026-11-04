<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Actions\AntiCheat\RiskSignalQuery;
use App\Actions\AntiCheat\SignalEvidence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\RiskSignalIndexRequest;
use App\Http\Requests\Api\V1\StaffRiskRequest;
use App\Http\Resources\Api\V1\RiskSignalDetailResource;
use App\Http\Resources\Api\V1\RiskSignalResource;
use App\Models\RiskSignal;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;

class RiskSignalController extends Controller
{
    /** The open queue by default, keyset-paginated. `no-store` through the `staff/*` prefix. */
    public function index(RiskSignalIndexRequest $request, RiskSignalQuery $signals): AnonymousResourceCollection
    {
        $status = $request->status();
        $page = $signals->page($status === 'reviewed' || $status === 'all' ? $status : 'open', $request->filters(), $request->perPage())
            // The next/previous links must carry the filters and the page size, or page two would change them.
            ->appends(array_filter([...$request->filters(), 'status' => $status, 'per_page' => $request->perPage()], fn ($v) => $v !== null));

        return RiskSignalResource::collection($page);
    }

    /** The id is a plain string: the request judges the role first, and only then is the signal looked up. */
    public function show(StaffRiskRequest $request, string $signal, SignalEvidence $evidence): RiskSignalDetailResource
    {
        abort_unless(Str::isUlid($signal), 404);

        $model = RiskSignal::query()->with(['user', 'product', 'reviewer'])->find($signal);
        abort_if($model === null, 404);

        return RiskSignalDetailResource::make($model)->withEvidence($evidence->resolve($model));
    }
}
