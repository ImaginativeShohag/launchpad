<?php

use App\Actions\Comment\CreateComment;
use App\Enums\Role;
use App\Models\Comment;
use App\Models\Product;
use App\Models\Report;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';
require_once __DIR__.'/../../Support/ReportFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/** Every key name of a decoded JSON document, at any depth. */
function privacyKeys(mixed $node): array
{
    $keys = [];
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            array_push($keys, ...privacyKeys($value));
        }
    }

    return $keys;
}

/**
 * A reported world: a product launching today with a maker, a commenter whose comment (and a reply) is reported by a
 * reporter with a recognisable handle and email, and a staff member who dismissed an older report.
 *
 * @return array{reporter: User, author: User, maker: User, product: Product, comment: Comment}
 */
function privacyWorld(): array
{
    $maker = User::factory()->create(['handle' => 'maker_priv', 'email' => 'maker.private@example.com']);
    $author = User::factory()->create(['handle' => 'author_priv', 'email' => 'author.private@example.com']);
    $reporter = User::factory()->create(['handle' => 'reporter_priv', 'email' => 'reporter.private@example.com']);
    $product = Product::factory()->live(now('UTC')->toDateString())->create(['maker_id' => $maker->id, 'slug' => 'privacy-product']);
    $comment = app(CreateComment::class)->handle($author, $product->id, 'A comment people report', null);
    app(CreateComment::class)->handle($maker, $product->id, 'A reply from the maker', $comment->id);
    queueReport($comment, $reporter, null, 'abuse')->forceFill(['details' => 'Private details from the reporter'])->save();
    queueReport($product, $reporter);
    Report::factory()->forComment($comment)->dismissed(User::factory()->role(Role::Reviewer)->create(['handle' => 'rev_priv']))->create(['reporter_id' => User::factory()->create(['handle' => 'old_reporter'])->id]);

    return compact('reporter', 'author', 'maker', 'product', 'comment');
}

it('never shows the public who reported anything: no reporter handle, id, email, details or report count on any public read', function () {
    // Given a reported world
    ['reporter' => $reporter, 'product' => $product, 'comment' => $comment, 'maker' => $maker] = privacyWorld();
    $secrets = [$reporter->handle, $reporter->email, $reporter->id, 'Private details from the reporter', 'old_reporter', 'rev_priv'];

    // When a guest and an unrelated member read every public page that could show a comment, a product or a profile
    $urls = [
        '/api/v1/today', "/api/v1/products/{$product->slug}", "/api/v1/products/{$product->slug}/comments", "/api/v1/comments/{$comment->id}/replies",
        "/api/v1/users/{$maker->handle}", "/api/v1/users/{$maker->handle}/products", '/api/v1/leaderboards?period=all', '/api/v1/topics',
        "/api/v1/users/{$reporter->handle}",
    ];
    foreach ([null, User::factory()->create()] as $viewer) {
        $viewer === null ? $this->app['auth']->forgetGuards() : Sanctum::actingAs($viewer);
        foreach ($urls as $url) {
            $content = $this->getJson($url)->getContent();

            // Then none of the reporter's identifying data appears (the reporter's own profile shows only the public fields)
            foreach ($secrets as $secret) {
                if ($url === "/api/v1/users/{$reporter->handle}" && in_array($secret, [$reporter->handle, $reporter->id], true)) {
                    continue;
                }
                expect($content)->not->toContain($secret);
            }
            expect($content)->not->toContain('.private@example.com');
        }
    }
});

it('gives the reporter back only their own report: no others, no counts, no details of anyone else', function () {
    // Given a comment reported by two members
    $comment = Comment::factory()->create(['product_id' => commentable()->id]);
    queueReport($comment, User::factory()->create(['handle' => 'other_reporter']), null, 'abuse');
    Sanctum::actingAs(User::factory()->create());

    // When a third member reports it
    $response = $this->postJson("/api/v1/comments/{$comment->id}/report", ['reason' => 'spam', 'details' => 'My own details']);

    // Then the response has the report's own fields only
    $response->assertCreated();
    expect(array_keys($response->json('data')))->toEqualCanonicalizing(['id', 'type', 'target_id', 'reason', 'status', 'created_at']);
    expect($response->getContent())->not->toContain('other_reporter');
});

it('shows staff handles but never an email, a password hash, a token or a network hash, on every staff and admin response', function () {
    // Given a reported world and an admin who works the queue
    ['reporter' => $reporter, 'comment' => $comment, 'product' => $product] = privacyWorld();
    $admin = signedInStaff(Role::Admin);
    $target = User::factory()->create(['handle' => 'target_priv', 'email' => 'target.private@example.com']);

    // When every staff and admin read and write responds
    $responses = [
        $this->getJson('/api/v1/staff/reports'),
        $this->getJson('/api/v1/staff/reports/history'),
        $this->getJson("/api/v1/staff/reports/comment/{$comment->id}"),
        $this->getJson("/api/v1/staff/reports/product/{$product->id}"),
        $this->postJson("/api/v1/admin/users/{$target->handle}/suspend", ['reason' => 'Reason']),
        $this->postJson("/api/v1/admin/users/{$target->handle}/restore"),
        $this->postJson("/api/v1/staff/reports/comment/{$comment->id}/resolve", ['action' => 'dismiss']),
    ];

    // Then no response carries an email address or a secret-looking key, and reporters appear by handle only
    foreach ($responses as $response) {
        $response->assertOk();
        $content = $response->getContent();
        expect($content)->not->toContain('@example.com')->not->toContain($admin->email);
        expect(array_intersect(privacyKeys($response->json()), ['email', 'password', 'remember_token', 'ip_hash', 'device_hash', 'user_agent', 'pending_email', 'token', 'risk_score']))->toBe([]);
    }
    expect($responses[0]->getContent())->not->toContain('reporter_priv');
    expect($responses[2]->getContent())->toContain('reporter_priv')->not->toContain($reporter->email);
    // And the suspension response names neither an email nor a display name
    expect(array_keys($responses[4]->json('data')))->toBe(['id', 'handle', 'is_suspended', 'suspended_at', 'suspended_reason']);
});

it('keeps report text and reporter ids out of the admin audit log API for a resolved report', function () {
    // Given a reported comment resolved by an admin with a note
    ['comment' => $comment, 'reporter' => $reporter] = privacyWorld();
    signedInStaff(Role::Admin);
    $this->postJson("/api/v1/staff/reports/comment/{$comment->id}/resolve", ['action' => 'remove_comment', 'note' => 'Abusive'])->assertOk();

    // When the audit log is read
    $content = $this->getJson('/api/v1/admin/audit-logs')->assertOk()->getContent();

    // Then the rows hold ids, counts and the staff note, but not the comment body, the reporter's details or any email
    expect($content)->toContain('comment.removed')->toContain('report.resolved')->toContain('Abusive');
    expect($content)->not->toContain('A comment people report')->not->toContain('Private details from the reporter')->not->toContain($reporter->email)->not->toContain($reporter->id);
});

it('has no comment-state leak in the audit table: meta holds no body text', function () {
    // Given a removal
    ['comment' => $comment] = privacyWorld();
    signedInStaff(Role::Admin);
    $this->postJson("/api/v1/staff/reports/comment/{$comment->id}/resolve", ['action' => 'remove_comment', 'note' => 'Abusive'])->assertOk();

    // When the raw meta is read
    $meta = DB::table('audit_logs')->whereIn('action', ['comment.removed', 'report.resolved'])->pluck('meta')->implode(' ');

    // Then the body is not in it
    expect($meta)->not->toContain('A comment people report');
});
