<?php

use App\Actions\Comment\DeleteComment;
use App\Actions\Report\CreateReport;
use App\Enums\ReportReason;
use App\Models\Comment;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/** Every statement of the callback in order, lower-cased. */
function reportStatements(Closure $callback): array
{
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $log[] = strtolower($query->sql);
    });
    $callback();

    return $log;
}

/** "table:mode" for each locking statement, in order. */
function reportLockSequence(array $log): array
{
    $sequence = [];
    foreach ($log as $sql) {
        $mode = str_contains($sql, 'for update') ? 'update' : (str_contains($sql, 'for share') ? 'share' : null);
        if ($mode === null) {
            continue;
        }
        foreach (['products', 'comments', 'reports', 'users'] as $table) {
            if (str_contains($sql, 'from "'.$table.'"')) {
                $sequence[] = "{$table}:{$mode}";
                break;
            }
        }
    }

    return $sequence;
}

it('locks product FOR SHARE, then the comment FOR SHARE, then the reporter FOR UPDATE last, before any report is counted or inserted', function () {
    // Given a reporter and somebody else's comment
    $reporter = User::factory()->create();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When they report it
    $log = reportStatements(fn () => app(CreateReport::class)->handle($reporter, 'comment', $comment->id, ReportReason::Spam, null));

    // Then the locks come in the global order, with no exclusive product or comment lock
    expect(reportLockSequence($log))->toBe(['products:share', 'comments:share', 'users:update']);
    $userLock = collect($log)->search(fn (string $sql) => str_contains($sql, 'from "users"') && str_contains($sql, 'for update'));
    $countAt = collect($log)->search(fn (string $sql) => str_contains($sql, 'count(*)') && str_contains($sql, '"reports"'));
    $insertAt = collect($log)->search(fn (string $sql) => str_starts_with($sql, 'insert into "reports"'));
    expect($userLock)->toBeLessThan($countAt)->and($countAt)->toBeLessThan($insertAt);
});

it('locks product FOR SHARE then the reporter FOR UPDATE for a product report', function () {
    // Given a reporter and a public product
    $reporter = User::factory()->create();
    $product = commentable();

    // When they report it
    $log = reportStatements(fn () => app(CreateReport::class)->handle($reporter, 'product', $product->id, ReportReason::Spam, null));

    // Then the product is share-locked before the user row
    expect(reportLockSequence($log))->toBe(['products:share', 'users:update']);
});

it('answers 404 and writes nothing when the target stops being reportable between the check and the lock', function (string $case) {
    // Given a reportable target that is changed by "another request" right before the product lock statement runs
    $reporter = signedInMember();
    $product = commentable();
    $comment = Comment::factory()->create(['product_id' => $product->id]);
    $author = $comment->user_id;
    $changed = false;
    DB::beforeExecuting(function (string $query) use (&$changed, $case, $product, $comment, $author) {
        $sql = strtolower($query);
        if ($changed || ! str_contains($sql, 'for share') || ! str_contains($sql, 'from "products"')) {
            return;
        }
        $changed = true;
        match ($case) {
            'comment deleted by its author' => DB::table('comments')->where('id', $comment->id)->update(['deleted_at' => now(), 'body' => '']),
            'comment removed by staff' => DB::table('comments')->where('id', $comment->id)->update(['removed_at' => now(), 'removed_by' => User::factory()->create()->id, 'removed_reason' => 'spam']),
            'product taken down' => DB::table('products')->where('id', $product->id)->update(['status' => 'taken_down', 'taken_down_at' => now()]),
            'author account deleted' => DB::table('users')->where('id', $author)->update(['deleted_at' => now()]),
        };
    });

    // When the member reports the comment (and, for the take-down case, the product too)
    $response = $this->postJson("/api/v1/comments/{$comment->id}/report", ['reason' => 'spam']);

    // Then it is the uniform 404 and no report row exists
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Report::query()->count())->toBe(0)->and($changed)->toBeTrue();
})->with(['comment deleted by its author', 'comment removed by staff', 'product taken down', 'author account deleted']);

it('answers 404 for a product taken down between the check and the lock of a product report', function () {
    // Given a public product that is taken down right before the product lock statement
    signedInMember();
    $product = commentable();
    $changed = false;
    DB::beforeExecuting(function (string $query) use (&$changed, $product) {
        $sql = strtolower($query);
        if (! $changed && str_contains($sql, 'for share') && str_contains($sql, 'from "products"')) {
            $changed = true;
            DB::table('products')->where('id', $product->id)->update(['status' => 'taken_down', 'taken_down_at' => now()]);
        }
    });

    // When it is reported
    $response = $this->postJson("/api/v1/products/{$product->id}/report", ['reason' => 'spam']);

    // Then 404 and nothing written
    $response->assertNotFound();
    expect(Report::query()->count())->toBe(0);
});

it('does not leave a report open on a comment the author deleted while the report waited for the lock', function () {
    // Given the author's comment, deleted through the real action (which closes its open reports) right before the report's lock
    $reporter = User::factory()->create();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    DB::table('products')->where('id', $comment->product_id)->update(['comments_count' => 1]);
    $author = $comment->author;
    $deleted = false;
    DB::beforeExecuting(function (string $query) use (&$deleted, $author, $comment) {
        if (! $deleted && str_contains(strtolower($query), 'for share') && str_contains(strtolower($query), 'from "products"')) {
            $deleted = true;
            app(DeleteComment::class)->handle($author, $comment->id);
        }
    });

    // When the report runs
    // Then it is refused, so no open report is left on a deleted comment (the delete had already closed zero reports)
    expect(fn () => app(CreateReport::class)->handle($reporter, 'comment', $comment->id, ReportReason::Spam, null))->toThrow(ModelNotFoundException::class);
    expect(Report::query()->open()->count())->toBe(0);
});
