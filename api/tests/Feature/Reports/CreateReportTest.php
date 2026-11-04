<?php

use App\Actions\Report\CreateReport;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));
afterEach(function () {
    Report::flushEventListeners();
    DB::flushQueryLog();
});

function reportComment(string $id, array $payload = ['reason' => 'spam'])
{
    return test()->postJson("/api/v1/comments/{$id}/report", $payload);
}

function reportProduct(string $id, array $payload = ['reason' => 'spam'])
{
    return test()->postJson("/api/v1/products/{$id}/report", $payload);
}

it('reports a comment: 201, one open row, the documented resource keys, no handler fields', function () {
    // Given a member and somebody else's comment on a public product
    $me = signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When they report it as spam
    $response = reportComment($comment->id);

    // Then it is created, open, and the resource has exactly the documented keys
    $response->assertCreated();
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'type', 'target_id', 'reason', 'status', 'created_at']);
    $response->assertJsonPath('data.type', 'comment')->assertJsonPath('data.target_id', $comment->id)->assertJsonPath('data.reason', 'spam')->assertJsonPath('data.status', 'open');
    $row = Report::query()->sole();
    expect($row->reporter_id)->toBe($me->id)->and($row->status)->toBe(ReportStatus::Open)->and($row->handled_by)->toBeNull()
        ->and($row->handled_at)->toBeNull()->and($row->resolution_note)->toBeNull()->and($row->details)->toBeNull();
});

it('reports a product: 201 with the product type', function () {
    // Given a member and a public product
    signedInMember();
    $product = commentable();

    // When they report it
    $response = reportProduct($product->id, ['reason' => 'misleading']);

    // Then it is created
    $response->assertCreated()->assertJsonPath('data.type', 'product')->assertJsonPath('data.target_id', $product->id)->assertJsonPath('data.reason', 'misleading');
    expect(Report::query()->sole()->reportable_type)->toBe('product');
});

it('lets a verified reviewer or admin report too, and a maker report somebody else\'s product', function () {
    // Given a reviewer, an admin and a maker of a different product
    $product = commentable();
    foreach ([Role::Reviewer, Role::Admin] as $role) {
        signedInStaff($role);
        reportProduct($product->id)->assertCreated();
    }
    $maker = signedInMember();
    Product::factory()->live()->create(['maker_id' => $maker->id]);

    // When the maker reports the other product
    // Then it is accepted
    reportProduct($product->id)->assertCreated();
    expect(Report::query()->count())->toBe(3);
});

it('refuses own content with 403 own_content: the maker of a product, the author of a comment or a reply', function (string $case) {
    // Given a member and their own product or comment
    $me = signedInMember();
    $product = commentable(['maker_id' => $me->id]);
    $top = Comment::factory()->create(['product_id' => $product->id, 'user_id' => $me->id]);
    $reply = Comment::factory()->replyTo($top)->create(['user_id' => $me->id]);

    // When they report it
    $response = match ($case) {
        'product' => reportProduct($product->id),
        'comment' => reportComment($top->id),
        'reply' => reportComment($reply->id),
    };

    // Then it is 403 with the stable code and nothing is written
    $response->assertForbidden()->assertJsonPath('code', 'own_content');
    expect(Report::query()->count())->toBe(0);
})->with(['product', 'comment', 'reply']);

it('answers the identical 404 for every hidden or unknown target', function (string $kind, string $case) {
    // Given a member and a target that must not be reportable
    $me = signedInMember();
    $maker = User::factory()->create();
    $deletedMaker = User::factory()->create(['deleted_at' => now()]);
    $live = Product::factory()->live()->create(['maker_id' => $maker->id]);
    $author = User::factory()->create();
    $products = [
        'draft' => Product::factory()->create(), 'pending' => Product::factory()->pending()->create(), 'approved' => Product::factory()->approved()->create(),
        'scheduled' => Product::factory()->scheduled()->create(), 'rejected' => Product::factory()->rejected()->create(),
        'taken down' => Product::factory()->takenDown()->create(), 'deleted maker' => Product::factory()->live()->create(['maker_id' => $deletedMaker->id]),
        'launching tomorrow' => Product::factory()->live(now('UTC')->addDay()->toDateString())->create(),
        'soft-deleted' => tap(Product::factory()->live()->create(), fn (Product $p) => $p->delete()),
    ];
    $id = match (true) {
        $case === 'unknown' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', $case === 'not a ulid' => 'nope', $case === 'nul byte' => 'a%00b', $case === '200 chars' => str_repeat('a', 200),
        $kind === 'product' => $products[$case]->id,
        $case === 'removed comment' => Comment::factory()->removed()->create(['product_id' => $live->id, 'user_id' => $author->id])->id,
        $case === 'deleted comment' => Comment::factory()->deletedByAuthor()->create(['product_id' => $live->id, 'user_id' => $author->id])->id,
        $case === 'deleted author' => Comment::factory()->create(['product_id' => $live->id, 'user_id' => User::factory()->create(['deleted_at' => now()])->id])->id,
        default => Comment::factory()->create(['product_id' => $products[$case]->id, 'user_id' => $author->id])->id,
    };

    // When they report it
    $response = $kind === 'product' ? reportProduct($id) : reportComment($id);

    // Then the answer is the one plain 404 and nothing was written
    $response->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    expect(Report::query()->count())->toBe(0);
})->with(function () {
    $cases = [];
    foreach (['unknown', 'not a ulid', 'nul byte', '200 chars'] as $case) {
        $cases["product {$case}"] = ['product', $case];
        $cases["comment {$case}"] = ['comment', $case];
    }
    foreach (['draft', 'pending', 'approved', 'scheduled', 'rejected', 'taken down', 'deleted maker', 'launching tomorrow', 'soft-deleted'] as $case) {
        $cases["product {$case}"] = ['product', $case];
        $cases["comment on {$case} product"] = ['comment', $case];
    }
    foreach (['removed comment', 'deleted comment', 'deleted author'] as $case) {
        $cases[$case] = ['comment', $case];
    }

    return $cases;
});

it('applies the route guards: guest 401, unverified 403, suspended 403', function () {
    // Given a reportable comment
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When a guest, an unverified and a suspended member report
    $guest = reportComment($comment->id);
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unverified = reportComment($comment->id);
    Sanctum::actingAs(User::factory()->suspended()->create());
    $suspended = reportProduct($comment->product_id);

    // Then the guards answer first
    $guest->assertUnauthorized();
    $unverified->assertForbidden()->assertJsonPath('code', 'email_unverified');
    $suspended->assertForbidden()->assertJsonPath('code', 'account_unavailable');
    expect(Report::query()->count())->toBe(0);
});

it('throws for a stale reporter model suspended or deleted between the middleware and the lock', function (string $state) {
    // Given a reporter whose account changed after the request was authorised
    $reporter = User::factory()->create();
    $product = commentable();
    DB::table('users')->where('id', $reporter->id)->update($state === 'suspended' ? ['suspended_at' => now()] : ['deleted_at' => now()]);

    // When the action runs
    // Then it refuses and writes nothing
    expect(fn () => app(CreateReport::class)->handle($reporter, 'product', $product->id, ReportReason::Spam, null))->toThrow(AuthorizationException::class);
    expect(Report::query()->count())->toBe(0);
})->with(['suspended', 'deleted']);

it('requires details for the reason other and accepts them', function (mixed $details, bool $accepted) {
    // Given a member and a comment
    signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    $payload = ['reason' => 'other'];
    if ($details !== '@missing') {
        $payload['details'] = $details;
    }

    // When they report with the reason "other"
    $response = reportComment($comment->id, $payload);

    // Then missing, empty, blank and invisible-only details are 422 on `details`; real text is stored
    if ($accepted) {
        $response->assertCreated();
        expect(Report::query()->sole()->details)->toBe(trim((string) $details));
    } else {
        $response->assertStatus(422)->assertJsonValidationErrors('details');
        expect(Report::query()->count())->toBe(0);
    }
})->with([
    'missing' => ['@missing', false], 'empty' => ['', false], 'whitespace' => ['   ', false], 'bidi only' => ["\u{202E}\u{202C}", false], 'null' => [null, false],
    'array' => [['x'], false], 'object' => [['a' => 'b'], false], 'number' => [12, false],
    'text' => ['It impersonates another maker', true], 'text with spaces' => ['  It impersonates  ', true],
]);

it('validates details for every reason: over 1000, invalid UTF-8, arrays; cleans control characters', function () {
    // Given a member and a comment
    signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When bad details are sent
    // Then they are 422, never 500
    reportComment($comment->id, ['reason' => 'spam', 'details' => str_repeat('a', 1001)])->assertStatus(422)->assertJsonValidationErrors('details');
    reportComment($comment->id, ['reason' => 'spam', 'details' => "bad \xB1\xB2 bytes"])->assertStatus(422);
    reportComment($comment->id, ['reason' => 'spam', 'details' => ['x']])->assertStatus(422);
    reportComment($comment->id, ['reason' => 'spam', 'details' => ['a' => ['b']]])->assertStatus(422);
    expect(Report::query()->count())->toBe(0);

    // And exactly 1000 characters pass, control characters are stripped, other reasons need no details
    reportComment($comment->id, ['reason' => 'spam', 'details' => "Line one\x00\x07\r\n\r\n\r\n\r\nLine two\u{202E}"])->assertCreated();
    expect(Report::query()->sole()->details)->toBe("Line one\n\nLine two");
    $this->travel(61)->seconds(); // the five rejected requests above used the minute's report budget
    $other = Comment::factory()->create(['product_id' => $comment->product_id]);
    reportComment($other->id, ['reason' => 'abuse', 'details' => str_repeat('é', 1000)])->assertCreated();
    $third = Comment::factory()->create(['product_id' => $comment->product_id]);
    reportComment($third->id, ['reason' => 'inappropriate'])->assertCreated();
});

it('rejects a bad reason with 422', function (mixed $reason) {
    // Given a member and a comment
    signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When the reason is unknown, missing or the wrong type
    $payload = $reason === '@missing' ? [] : ['reason' => $reason];
    $response = reportComment($comment->id, $payload);

    // Then it is 422 on `reason` and nothing is written
    $response->assertStatus(422)->assertJsonValidationErrors('reason');
    expect(Report::query()->count())->toBe(0);
})->with([
    'unknown' => ['rude'], 'upper case' => ['SPAM'], 'empty' => [''], 'null' => [null], 'missing' => ['@missing'],
    'array' => [['spam']], 'object' => [['a' => 'spam']], 'number' => [3], 'true' => [true],
]);

it('rejects non-JSON-object bodies and ignores extra fields', function () {
    // Given a member and a comment
    signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When the body is a bare list or has unknown fields
    $list = $this->postJson("/api/v1/comments/{$comment->id}/report", ['spam']);
    $extra = reportComment($comment->id, ['reason' => 'spam', 'status' => 'resolved', 'handled_by' => 'x', 'reporter_id' => 'y']);

    // Then the list is 422, the extra fields are ignored (the row is open and owned by the caller)
    $list->assertStatus(422);
    $extra->assertCreated()->assertJsonPath('data.status', 'open');
    expect(Report::query()->sole()->handled_by)->toBeNull();
});

it('answers a repeat of an open report with 200 and the same report, writing nothing', function () {
    // Given a member who reported a comment
    $me = signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    $first = reportComment($comment->id, ['reason' => 'spam']);

    // When they report it again, even with another reason
    $second = reportComment($comment->id, ['reason' => 'abuse']);
    $action = app(CreateReport::class)->handle($me, 'comment', $comment->id, ReportReason::Abuse, null);

    // Then it is 200 with the same id, still one row with the first reason, and the action says nothing was created
    $second->assertOk()->assertJsonPath('data.id', $first->json('data.id'))->assertJsonPath('data.reason', 'spam');
    expect(Report::query()->count())->toBe(1)->and($action['created'])->toBeFalse();
});

it('lets a different member report the same target (a second row)', function () {
    // Given a reported comment
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    signedInMember();
    reportComment($comment->id)->assertCreated();

    // When another member reports it
    signedInMember();
    $response = reportComment($comment->id);

    // Then a second row exists and the answer never says how many others reported
    $response->assertCreated();
    expect(Report::query()->count())->toBe(2);
    expect($response->getContent())->not->toContain('count')->not->toContain('others');
});

it('answers 409 already_reviewed after a dismissed report, but lets a member report again after a resolved one', function () {
    // Given a member whose report on one comment was dismissed and whose report on another was resolved
    $me = signedInMember();
    $product = commentable();
    $dismissed = Comment::factory()->create(['product_id' => $product->id]);
    $resolved = Comment::factory()->create(['product_id' => $product->id]);
    Report::factory()->forComment($dismissed)->dismissed(User::factory()->role(Role::Reviewer)->create())->create(['reporter_id' => $me->id]);
    Report::factory()->forComment($resolved)->resolved()->create(['reporter_id' => $me->id]);

    // When they report each again
    $again = reportComment($dismissed->id);
    $afterResolved = reportComment($resolved->id);

    // Then the dismissed target is closed to them (409) and the resolved one is open to a new report
    $again->assertStatus(409)->assertJsonPath('code', 'already_reviewed');
    $afterResolved->assertCreated();
    expect(Report::query()->where('reporter_id', $me->id)->open()->count())->toBe(1);
});

it('enforces the open cap: the 3rd distinct report is 409 report_limit, a repeat at the cap is still 200, resolving frees a slot', function () {
    // Given a cap of 2 and a member with two open reports
    $admin = User::factory()->role(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::CommunityReportOpenCap, 2, $admin);
    $me = signedInMember();
    $product = commentable();
    [$a, $b, $c] = Comment::factory()->count(3)->create(['product_id' => $product->id])->all();
    reportComment($a->id)->assertCreated();
    $reportB = reportComment($b->id)->assertCreated();

    // When they report a third target, repeat one of the first two, and then one report gets closed
    $third = reportComment($c->id);
    $repeat = reportComment($b->id);
    Report::query()->whereKey($reportB->json('data.id'))->get()->each(fn (Report $r) => DB::table('reports')->where('id', $r->id)->update(['status' => 'resolved', 'handled_at' => now()]));
    $afterFree = reportComment($c->id);

    // Then the third is 409, the repeat is 200 (idempotent even at the cap), and the freed slot lets the third through
    $third->assertStatus(409)->assertJsonPath('code', 'report_limit');
    $repeat->assertOk();
    $afterFree->assertCreated();
    expect(Report::query()->where('reporter_id', $me->id)->open()->count())->toBe(2);
});

it('reads the cap live and falls back to 20 for a corrupt stored value', function () {
    // Given a member with two open reports and a cap of 2
    $admin = User::factory()->role(Role::Admin)->create();
    app(Settings::class)->set(SettingKey::CommunityReportOpenCap, 2, $admin);
    $me = signedInMember();
    $comments = Comment::factory()->count(5)->create(['product_id' => commentable()->id]);
    reportComment($comments[0]->id)->assertCreated();
    reportComment($comments[1]->id)->assertCreated();
    reportComment($comments[2]->id)->assertStatus(409);

    // When the admin raises it to 3, and later a corrupt value is written straight into the table
    app(Settings::class)->set(SettingKey::CommunityReportOpenCap, 3, $admin);
    $raised = reportComment($comments[2]->id);
    DB::table('settings')->where('key', 'community.report_open_cap')->update(['value' => json_encode(0)]);
    Cache::flush();
    $corrupt = reportComment($comments[3]->id);

    // Then the raise took effect at once, and the corrupt 0 did not lock everyone out (default 20)
    $raised->assertCreated();
    $corrupt->assertCreated();
});

it('never hides anything: after many reports the product and comment still render and counts are unchanged', function () {
    // Given a product with a comment, both reported by several members
    $product = commentable();
    $comment = Comment::factory()->create(['product_id' => $product->id, 'body' => 'A fine comment']);
    DB::table('products')->where('id', $product->id)->update(['comments_count' => 1]);
    foreach (range(1, 4) as $_) {
        signedInMember();
        reportComment($comment->id)->assertCreated();
        reportProduct($product->id)->assertCreated();
    }
    $this->app['auth']->forgetGuards();

    // When the public reads are made
    $page = $this->getJson("/api/v1/products/{$product->slug}/comments")->assertOk();
    $single = $this->getJson("/api/v1/products/{$product->slug}")->assertOk();

    // Then the comment is visible and the counter is unchanged
    expect($page->json('data.0.body'))->toBe('A fine comment')->and($single->json('data.comments_count'))->toBe(1);
    expect(productCommentCounters($product))->toBe([1, 1]);
});

it('answers 200 with the existing report when a parallel request wins the race, and keeps the transaction usable', function () {
    // Given a member and a comment, and a competing report that lands between the cap check and the insert
    $me = signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    $inserted = false;
    DB::beforeExecuting(function (string $query) use ($me, $comment, &$inserted) {
        if (! $inserted && str_contains(strtolower($query), 'count(*)') && str_contains($query, '"reports"')) {
            $inserted = true;
            DB::table('reports')->insert(['id' => (string) Str::ulid(), 'reporter_id' => $me->id, 'reportable_type' => 'comment', 'reportable_id' => $comment->id,
                'reason' => 'abuse', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        }
    });

    // When the member reports (the unique index fires inside the savepoint)
    $response = reportComment($comment->id, ['reason' => 'spam']);

    // Then the competitor's row is the answer (200), exactly one row exists, and the request did not fail with 25P02
    $response->assertOk()->assertJsonPath('data.reason', 'abuse');
    expect(Report::query()->count())->toBe(1);
});

it('sends no-store, private on report writes and refusals', function () {
    // Given a member
    signedInMember();
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);

    // When a report, a refusal and a validation error are sent
    $ok = reportComment($comment->id);
    $missing = reportProduct('01ARZ3NDEKTSV4RRFFQ69G5FAV');
    $invalid = reportComment($comment->id, ['reason' => ['x']]);

    // Then none is cacheable
    foreach ([$ok, $missing, $invalid] as $response) {
        expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    }
});

it('throttles reports at 5 per minute with Retry-After', function () {
    // Given a member who used the five reports of the minute
    signedInMember();
    $comments = Comment::factory()->count(6)->create(['product_id' => commentable()->id]);
    foreach ($comments->take(5) as $comment) {
        reportComment($comment->id)->assertCreated();
    }

    // When a sixth arrives
    // Then it is throttled
    reportComment($comments[5]->id)->assertStatus(429)->assertHeader('Retry-After');
    expect(Report::query()->count())->toBe(5);
});
