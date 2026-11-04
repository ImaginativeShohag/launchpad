<?php

use App\Enums\CommentSort;
use App\Enums\CommentState;
use App\Models\Comment;
use App\Models\User;

function bareComment(array $attributes = [], ?User $author = null): Comment
{
    $comment = new Comment;
    $comment->forceFill($attributes + ['removed_at' => null, 'deleted_at' => null]);

    if ($author !== null) {
        $comment->setRelation('author', $author);
    }

    return $comment;
}

it('maps a comment to its public state', function (array $attributes, ?array $authorState, CommentState $expected) {
    // Given a comment row and, optionally, its loaded author
    $author = $authorState === null ? null : (new User)->forceFill($authorState);

    // When the state is read
    // Then removed wins over deleted, a deleted account reads as deleted, anything else is visible
    expect(bareComment($attributes, $author)->state())->toBe($expected);
})->with([
    'visible' => [[], null, CommentState::Visible],
    'visible with a live author' => [[], ['deleted_at' => null], CommentState::Visible],
    'removed' => [['removed_at' => '2026-10-21 10:00:00'], null, CommentState::Removed],
    'deleted' => [['deleted_at' => '2026-10-21 10:00:00'], null, CommentState::Deleted],
    'removed and deleted' => [['removed_at' => '2026-10-21 10:00:00', 'deleted_at' => '2026-10-21 10:00:00'], null, CommentState::Removed],
    'visible row, deleted author' => [[], ['deleted_at' => '2026-10-21 10:00:00'], CommentState::Deleted],
    'removed row, deleted author' => [['removed_at' => '2026-10-21 10:00:00'], ['deleted_at' => '2026-10-21 10:00:00'], CommentState::Removed],
]);

it('lists the sort and state values the API documents', function () {
    // Given the enums
    // When the values are read
    // Then they match the contract
    expect(CommentSort::values())->toBe(['top', 'newest']);
    expect(array_map(fn (CommentState $s) => $s->value, CommentState::cases()))->toBe(['visible', 'removed', 'deleted']);
});
