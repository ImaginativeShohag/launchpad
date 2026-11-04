<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Admin\CreateTopic;
use App\Actions\Admin\DeleteTopic;
use App\Actions\Admin\UpdateTopic;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ManageTopicRequest;
use App\Http\Requests\Api\V1\StoreTopicRequest;
use App\Http\Requests\Api\V1\UpdateTopicRequest;
use App\Http\Resources\Api\V1\TopicResource;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class TopicController extends Controller
{
    public function store(StoreTopicRequest $request, CreateTopic $create): JsonResponse
    {
        $topic = $create->handle($this->authenticated($request), $request->validated());

        return TopicResource::make($topic)->response()->setStatusCode(201);
    }

    public function update(UpdateTopicRequest $request, Topic $topic, UpdateTopic $update): TopicResource
    {
        return TopicResource::make($update->handle($this->authenticated($request), $topic, $request->validated()));
    }

    public function destroy(ManageTopicRequest $request, Topic $topic, DeleteTopic $delete): Response
    {
        $delete->handle($this->authenticated($request), $topic);

        return response()->noContent();
    }
}
