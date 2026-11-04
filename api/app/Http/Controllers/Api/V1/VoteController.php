<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Vote\CastVote;
use App\Actions\Vote\VoteSignals;
use App\Actions\Vote\WithdrawVote;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\VoteRequest;
use App\Http\Resources\Api\V1\VoteResource;

class VoteController extends Controller
{
    // `$product` is a plain string on purpose: the action decides visibility under the lock, so every hidden case is one 404.
    public function store(VoteRequest $request, string $product, CastVote $cast): VoteResource
    {
        return VoteResource::make($cast->handle($this->authenticated($request), $product, VoteSignals::fromRequest($request)));
    }

    public function destroy(VoteRequest $request, string $product, WithdrawVote $withdraw): VoteResource
    {
        return VoteResource::make($withdraw->handle($this->authenticated($request), $product));
    }
}
