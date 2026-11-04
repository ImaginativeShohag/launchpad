<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Support\Collection;

/** A page of comments for one viewer of one product (the window and the maker are the same for every row). */
class CommentCollection extends ResourceCollection
{
    public $collects = CommentResource::class;

    public function __construct($resource, private readonly ?User $viewer, private readonly int $editMinutes, private readonly string $makerId)
    {
        parent::__construct($resource);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(Request $request): array
    {
        /** @var Collection<int, CommentResource> $comments */
        $comments = $this->collection;

        return $comments
            ->map(fn (CommentResource $comment) => $comment->viewedBy($this->viewer, $this->editMinutes, $this->makerId)->toArray($request))
            ->all();
    }
}
