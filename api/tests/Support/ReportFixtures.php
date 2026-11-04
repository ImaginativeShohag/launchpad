<?php

use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** An open report of a comment or product by `$reporter` (default: a fresh verified member) at `$at`. */
function queueReport(Comment|Product $target, ?User $reporter = null, ?string $at = null, string $reason = 'spam'): Report
{
    $moment = $at === null ? now() : Carbon::parse($at, 'UTC');

    return Report::factory()
        ->when($target instanceof Comment, fn ($factory) => $factory->forComment($target), fn ($factory) => $factory->forProduct($target))
        ->create(['reporter_id' => ($reporter ?? User::factory()->create())->id, 'reason' => $reason, 'created_at' => $moment, 'updated_at' => $moment]);
}

/**
 * A reporter with a closed track record: `$upheld` reports a staff member resolved, `$dismissed` dismissed ones,
 * `$system` closed by the system (which must not count either way). Reliability = (upheld + 1) / (decided + 2).
 */
function queueReporter(int $upheld = 0, int $dismissed = 0, int $system = 0): User
{
    $reporter = User::factory()->create();
    $staff = User::factory()->role(Role::Reviewer)->create();
    $comment = Comment::factory()->create();

    for ($i = 0; $i < $upheld; $i++) {
        Report::factory()->forComment($comment)->resolved($staff)->create(['reporter_id' => $reporter->id]);
    }

    for ($i = 0; $i < $dismissed; $i++) {
        Report::factory()->forComment($comment)->dismissed($staff)->create(['reporter_id' => $reporter->id]);
    }

    for ($i = 0; $i < $system; $i++) {
        Report::factory()->forComment($comment)->systemResolved()->create(['reporter_id' => $reporter->id]);
    }

    return $reporter;
}

/** A comment on `$product` (default: a fresh live one) by a fresh member. */
function queueComment(?Product $product = null, array $attributes = []): Comment
{
    return Comment::factory()->create(['product_id' => ($product ?? Product::factory()->live()->create())->id, ...$attributes]);
}

/** @return list<string> the target ids of a queue response, in order */
function queueIds($response): array
{
    return array_map(fn (array $row) => $row['target']['id'], $response->json('data'));
}

/** The statements (lower-cased) that lock rows during the callback. */
function queueLockStatements(Closure $callback): array
{
    $log = [];
    DB::listen(function ($query) use (&$log) {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'for update') || str_contains($sql, 'for share')) {
            $log[] = $sql;
        }
    });
    $callback();

    return $log;
}
