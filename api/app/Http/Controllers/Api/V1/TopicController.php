<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TopicResource;
use App\Models\Topic;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TopicController extends Controller
{
    /** The list is staff-managed and small, so it is not paginated. */
    public function index(): AnonymousResourceCollection
    {
        return TopicResource::collection(Topic::query()->orderBy('position')->orderBy('name')->orderBy('id')->get());
    }
}
