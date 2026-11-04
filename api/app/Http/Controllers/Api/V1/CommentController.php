<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Comment\CreateComment;
use App\Actions\Comment\DeleteComment;
use App\Actions\Comment\EditComment;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CreateCommentRequest;
use App\Http\Requests\Api\V1\EditCommentRequest;
use App\Http\Resources\Api\V1\CommentResource;
use App\Models\Comment;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    // `$product` and `$comment` are plain strings on purpose: the actions decide visibility under the lock, so every hidden case is one 404.
    public function store(CreateCommentRequest $request, string $product, CreateComment $create, Settings $settings): JsonResponse
    {
        $comment = $create->handle($this->authenticated($request), $product, $request->body(), $request->parentId());

        return $this->resource($request, $comment, $settings)->response()->setStatusCode(201);
    }

    public function update(EditCommentRequest $request, string $comment, EditComment $edit, Settings $settings): CommentResource
    {
        return $this->resource($request, $edit->handle($this->authenticated($request), $comment, $request->body()), $settings);
    }

    public function destroy(Request $request, string $comment, DeleteComment $delete, Settings $settings): CommentResource
    {
        return $this->resource($request, $delete->handle($this->authenticated($request), $comment), $settings);
    }

    private function resource(Request $request, Comment $comment, Settings $settings): CommentResource
    {
        return CommentResource::make($comment)->viewedBy($this->authenticated($request), $settings->integer(SettingKey::CommunityCommentEditMinutes), $comment->product->maker_id);
    }
}
