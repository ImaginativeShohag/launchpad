<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Email\UpdateEmailPreferences;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateEmailPreferencesRequest;
use App\Http\Resources\Api\V1\EmailPreferenceResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailPreferenceController extends Controller
{
    /** The six optional types; a missing row means on. */
    public function show(Request $request, UpdateEmailPreferences $preferences): AnonymousResourceCollection
    {
        return EmailPreferenceResource::collection($preferences->list($this->authenticated($request)->getKey()));
    }

    public function update(UpdateEmailPreferencesRequest $request, UpdateEmailPreferences $preferences): AnonymousResourceCollection
    {
        return EmailPreferenceResource::collection($preferences->handle($this->authenticated($request), $request->changes()));
    }
}
