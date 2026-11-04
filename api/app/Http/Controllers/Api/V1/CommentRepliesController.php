<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Comment\CommentTree;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommentRepliesRequest;
use App\Http\Resources\Api\V1\CommentCollection;
use App\Support\OptionalViewer;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class CommentRepliesController extends Controller
{
    public function __invoke(CommentRepliesRequest $request, CommentTree $tree, Settings $settings, string $comment): JsonResponse
    {
        // A reply id, an unknown id and a hidden product are all the same 404.
        $thread = $tree->findVisibleThread($comment);
        abort_if($thread === null, 404);

        $page = $tree->replies($thread, $request->perPage());
        $viewer = OptionalViewer::from($request);

        return OptionalViewer::cacheHeaders(
            (new CommentCollection($page, $viewer, $settings->integer(SettingKey::CommunityCommentEditMinutes), $thread->product->maker_id))->response(),
            $viewer,
        );
    }
}
