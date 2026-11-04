<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Comment\EditWindow;
use App\Enums\CommentState;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A comment as the public sees it. Expects `author` and `replyTo` loaded. A removed or deleted comment is a
 * placeholder: no body, no author. The removal reason and who removed it are never public.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    private const STAMP = 'Y-m-d\TH:i:s.u\Z';

    private ?User $viewer = null;

    private int $editMinutes = 0;

    private string $makerId = '';

    /** Who is looking (null: a guest, so no can_* keys), the edit window in minutes, and the product's maker. */
    public function viewedBy(?User $viewer, int $editMinutes, string $makerId): static
    {
        $this->viewer = $viewer;
        $this->editMinutes = $editMinutes;
        $this->makerId = $makerId;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $state = $this->state();
        $visible = $state === CommentState::Visible;
        $author = $visible ? $this->author : null;
        $replyTo = $visible ? $this->replyTo : null;

        $data = [
            'id' => $this->id,
            'parent_id' => $this->parent_id,
            'state' => $state->value,
            'body' => $visible ? $this->body : null,
            'author' => $author === null ? null : $this->authorCard($author),
            // A deleted account is not named any more.
            'reply_to' => $replyTo === null || $replyTo->isDeleted() ? null : ['id' => $replyTo->id, 'handle' => $replyTo->handle, 'name' => $replyTo->name],
            // Microseconds: clients de-duplicate and order by it.
            'created_at' => $this->created_at->copy()->utc()->format(self::STAMP),
            'edited_at' => $this->edited_at?->copy()->utc()->format(self::STAMP),
            'replies_count' => $this->replies_count,
        ];

        if ($this->relationLoaded('replies')) {
            $data['replies'] = $this->replies->map(fn (Comment $reply) => self::make($reply)->viewedBy($this->viewer, $this->editMinutes, $this->makerId)->toArray($request))->all();
        }

        if ($this->viewer !== null) {
            $own = $visible && $this->user_id === $this->viewer->getKey();
            $data['can_edit'] = $own && EditWindow::isOpen($this->resource, $this->editMinutes, now());
            $data['can_delete'] = $own;
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function authorCard(User $author): array
    {
        $suspended = $author->isSuspended();

        return [
            'id' => $author->id,
            'handle' => $author->handle,
            'name' => $author->name,
            'avatar_url' => $suspended ? null : $author->avatarUrl(),
            'is_suspended' => $suspended,
            'is_maker' => $author->getKey() === $this->makerId,
        ];
    }
}
