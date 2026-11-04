<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Comment\CommentTree;
use App\Enums\SettingKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\CommentListRequest;
use App\Http\Resources\Api\V1\CommentCollection;
use App\Models\Product;
use App\Rules\SlugFormat;
use App\Support\OptionalViewer;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;

class ProductCommentController extends Controller
{
    private const EMBEDDED_REPLIES = 3;

    public function index(CommentListRequest $request, CommentTree $tree, Settings $settings, string $slug): JsonResponse
    {
        $slug = strtolower($slug);

        // A value that cannot be a slug never reaches the database (a NUL byte would be a Postgres error).
        abort_unless(SlugFormat::matches($slug, 3, 80), 404);

        // Hidden, unknown and soft-deleted products are all the same plain 404.
        $product = Product::visible()->whereMakerAvailable()->where('slug', $slug)->first();
        abort_if($product === null, 404);

        $page = $tree->topLevel($product, $request->sort(), $request->perPage());
        $tree->embedReplies($page->items(), self::EMBEDDED_REPLIES);

        // A suspended or deleted caller is a guest.
        $viewer = OptionalViewer::from($request);

        return OptionalViewer::cacheHeaders(
            (new CommentCollection($page, $viewer, $settings->integer(SettingKey::CommunityCommentEditMinutes), $product->maker_id))->response(),
            $viewer,
        );
    }
}
