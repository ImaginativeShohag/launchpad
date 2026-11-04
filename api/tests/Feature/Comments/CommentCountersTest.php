<?php

use App\Actions\Comment\CreateComment;
use App\Actions\Comment\DeleteComment;
use App\Actions\Comment\EditComment;
use App\Enums\Role;
use App\Models\Comment;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/CommentFixtures.php';

it('keeps both counters equal to the counted rows through 80 random operations', function (int $seed) {
    // Given 4 members, 2 products and a seeded random sequence (a private generator: Faker shares mt_rand and would make it order dependent)
    $state = $seed;
    $rand = function (int $min, int $max) use (&$state): int {
        $state = ($state * 1103515245 + 12345) & 0x7FFFFFFF;

        return $min + ($state >> 8) % ($max - $min + 1);
    };
    $members = User::factory()->count(4)->create();
    $staff = User::factory()->role(Role::Reviewer)->create();
    $products = [commentable(), commentable()];
    $create = app(CreateComment::class);
    $delete = app(DeleteComment::class);
    $edit = app(EditComment::class);
    $made = [];

    // When 80 operations run
    foreach (range(1, 80) as $step) {
        $member = $members[$rand(0, 3)];
        $product = $products[$rand(0, 1)];
        $pool = array_values(array_filter($made, fn (Comment $c) => $c->product_id === $product->id));
        $pick = $pool === [] ? null : $pool[$rand(0, count($pool) - 1)];

        try {
            match ($rand(1, 6)) {
                1 => $made[] = $create->handle($member, $product->id, "Top comment {$step}", null),
                2, 3 => $made[] = $create->handle($member, $product->id, "Reply {$step}", $pick?->id),
                4 => $pick === null ? null : $edit->handle(User::query()->findOrFail($pick->user_id), $pick->id, "Edited {$step}"),
                5 => $pick === null ? null : $delete->handle(User::query()->findOrFail($pick->user_id), $pick->id),
                6 => $pick === null ? null : staffRemoveComment($pick, $staff->id),
            };
        } catch (ModelNotFoundException) {
            // A parent or comment that staff removed or the author deleted is a legitimate 404 in this sequence.
        }

        // Then no CHECK fired, and after every step the counters match the rows
        assertCommentCountersConsistent();
    }

    // And the sequence really exercised every kind of row
    expect(DB::table('comments')->whereNotNull('parent_id')->count())->toBeGreaterThan(5);
    expect(DB::table('comments')->whereNotNull('deleted_at')->count() + DB::table('comments')->whereNotNull('removed_at')->count())->toBeGreaterThan(3);
})->with([1, 7, 42]);
