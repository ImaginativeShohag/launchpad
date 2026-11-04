<?php

use App\Actions\Admin\CreateTopic;
use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Topic;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;

it('lets an admin create a topic and audits it', function () {
    // Given an admin
    $admin = signedInStaff(Role::Admin);

    // When they create a topic (the slug is lowercased and trimmed)
    $response = $this->postJson('/api/v1/admin/topics', [
        'slug' => ' Dev-Tools ', 'name' => 'Developer Tools', 'emoji' => '🛠️', 'description' => 'Tools for builders', 'position' => 3,
    ]);

    // Then it is stored and the audit row names only ids
    $response->assertCreated()->assertJsonPath('data.slug', 'dev-tools')->assertJsonPath('data.position', 3);
    $topic = Topic::query()->where('slug', 'dev-tools')->firstOrFail();
    $log = AuditLog::query()->where('action', 'topic.created')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id)->and($log->subject_id)->toBe($topic->id);
    expect($log->meta)->toEqual(['topic_id' => $topic->id]);
});

it('puts a new topic last when no position is given', function () {
    // Given a topic at position 4
    signedInStaff(Role::Admin);
    Topic::factory()->create(['position' => 4]);

    // When a topic is created without a position
    $this->postJson('/api/v1/admin/topics', ['slug' => 'newest', 'name' => 'Newest'])->assertCreated();

    // Then it follows the last one
    expect(Topic::query()->where('slug', 'newest')->value('position'))->toBe(5);
});

it('refuses invalid or duplicate slugs and over-long fields', function (array $payload, string $field) {
    // Given an admin and an existing topic
    signedInStaff(Role::Admin);
    Topic::factory()->create(['slug' => 'taken']);

    // When the payload is invalid
    $response = $this->postJson('/api/v1/admin/topics', array_merge(['slug' => 'fresh', 'name' => 'Fresh'], $payload));

    // Then it is a 422 on that field
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'too short' => [['slug' => 'a'], 'slug'],
    'space' => [['slug' => 'bad slug'], 'slug'],
    'double hyphen' => [['slug' => 'bad--slug'], 'slug'],
    'leading hyphen' => [['slug' => '-bad'], 'slug'],
    'non ascii' => [['slug' => 'ünï'], 'slug'],
    'too long' => [['slug' => str_repeat('a', 41)], 'slug'],
    'duplicate' => [['slug' => 'taken'], 'slug'],
    'name without letters' => [['name' => '!!!'], 'name'],
    'name too long' => [['name' => str_repeat('a', 61)], 'name'],
    'emoji too long' => [['emoji' => '123456789'], 'emoji'],
    'description too long' => [['description' => str_repeat('a', 281)], 'description'],
    'position negative' => [['position' => -1], 'position'],
    'position too big' => [['position' => 1001], 'position'],
    'position text' => [['position' => 'first'], 'position'],
]);

it('answers wrong-shaped values with 422 for every field on create', function (string $field, mixed $value) {
    // Given an admin
    signedInStaff(Role::Admin);

    // When a field is an array, object, number, boolean (or null/empty where required)
    $response = $this->postJson('/api/v1/admin/topics', array_merge(['slug' => 'fresh', 'name' => 'Fresh'], [$field => $value]));

    // Then it is a validation error, never a 500
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
    expect(Topic::query()->count())->toBe(0);
})->with(function () {
    $cases = [];
    foreach (['slug', 'name', 'emoji', 'description', 'position'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'true' => true, 'false' => false] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    foreach (['slug', 'name'] as $field) {
        $cases["$field null"] = [$field, null];
        $cases["$field empty"] = [$field, ''];
    }
    $cases['slug integer'] = ['slug', 123];
    $cases['name integer'] = ['name', 123];
    $cases['position float'] = ['position', 1.5];
    $cases['position null'] = ['position', null];
    $cases['position empty'] = ['position', ''];

    return $cases;
});

it('updates a topic partially and audits the changed fields', function () {
    // Given an admin and a topic
    $admin = signedInStaff(Role::Admin);
    $topic = Topic::factory()->create(['slug' => 'ai', 'name' => 'AI', 'position' => 1]);

    // When the name and position change but the slug is re-sent unchanged
    $response = $this->patchJson("/api/v1/admin/topics/{$topic->id}", ['name' => 'Artificial Intelligence', 'slug' => 'ai', 'position' => 7]);

    // Then the topic changes and the audit lists the changed field names only
    $response->assertOk()->assertJsonPath('data.name', 'Artificial Intelligence');
    expect($topic->fresh()->position)->toBe(7);
    $log = AuditLog::query()->where('action', 'topic.updated')->firstOrFail();
    expect($log->actor_id)->toBe($admin->id);
    expect($log->meta)->toEqual(['topic_id' => $topic->id, 'fields' => ['name', 'position']]);
});

it('writes no audit row when nothing changes, and refuses a slug owned by another topic', function () {
    // Given two topics
    signedInStaff(Role::Admin);
    $a = Topic::factory()->create(['slug' => 'aaa', 'name' => 'Aaa']);
    Topic::factory()->create(['slug' => 'bbb']);

    // When the same values are sent, and then the other topic's slug
    $same = $this->patchJson("/api/v1/admin/topics/{$a->id}", ['name' => 'Aaa']);
    $clash = $this->patchJson("/api/v1/admin/topics/{$a->id}", ['slug' => 'bbb']);

    // Then the first succeeds silently and the second is a 422
    $same->assertOk();
    expect(AuditLog::query()->count())->toBe(0);
    $clash->assertUnprocessable()->assertJsonValidationErrors('slug');
});

it('answers wrong-shaped values with 422 on update', function (string $field, mixed $value) {
    // Given an admin and a topic
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();

    // When a field has the wrong shape
    $response = $this->patchJson("/api/v1/admin/topics/{$topic->id}", [$field => $value]);

    // Then it is a 422
    $response->assertUnprocessable()->assertJsonValidationErrors($field);
})->with(function () {
    $cases = [];
    foreach (['slug', 'name', 'emoji', 'description', 'position'] as $field) {
        foreach (['array' => ['x'], 'empty array' => [], 'object' => ['a' => 'b'], 'true' => true] as $label => $value) {
            $cases["$field $label"] = [$field, $value];
        }
    }
    $cases['slug null'] = ['slug', null];
    $cases['name null'] = ['name', null];
    $cases['position null'] = ['position', null];
    $cases['position empty'] = ['position', ''];

    return $cases;
});

it('deletes an unused topic and audits it', function () {
    // Given an admin and an unused topic
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create(['slug' => 'gone']);

    // When it is deleted
    $this->deleteJson("/api/v1/admin/topics/{$topic->id}")->assertNoContent();

    // Then it is gone and audited
    expect(Topic::query()->count())->toBe(0);
    expect(AuditLog::query()->where('action', 'topic.deleted')->firstOrFail()->meta)->toEqual(['topic_id' => $topic->id, 'slug' => 'gone']);
});

it('refuses to delete a topic that has products with a 409', function () {
    // Given a topic used by a product
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();
    DB::table('product_topics')->insert(['product_id' => Product::factory()->create()->id, 'topic_id' => $topic->id]);

    // When it is deleted
    $response = $this->deleteJson("/api/v1/admin/topics/{$topic->id}");

    // Then it is a 409 with a stable code, and nothing changes or is audited
    $response->assertStatus(409)->assertJsonPath('code', 'topic_in_use');
    expect(Topic::query()->count())->toBe(1)->and(AuditLog::query()->count())->toBe(0);
});

it('answers 404 for an unknown topic', function () {
    // Given an admin
    signedInStaff(Role::Admin);

    // When an unknown topic is changed or deleted
    // Then it is a 404
    $this->patchJson('/api/v1/admin/topics/01JZZZZZZZZZZZZZZZZZZZZZZZ', ['name' => 'X'])->assertNotFound();
    $this->deleteJson('/api/v1/admin/topics/01JZZZZZZZZZZZZZZZZZZZZZZZ')->assertNotFound();
});

it('refuses members and reviewers before it even validates', function (Role $role) {
    // Given a non-admin and an existing topic
    signedInStaff($role);
    $topic = Topic::factory()->create();

    // When they send invalid input to every topic route
    $create = $this->postJson('/api/v1/admin/topics', ['slug' => ['x'], 'name' => []]);
    $update = $this->patchJson("/api/v1/admin/topics/{$topic->id}", ['slug' => ['x']]);
    $delete = $this->deleteJson("/api/v1/admin/topics/{$topic->id}");

    // Then all are 403 (not 422), and nothing changed
    $create->assertForbidden();
    $update->assertForbidden();
    $delete->assertForbidden();
    expect(Topic::query()->count())->toBe(1);
})->with([[Role::Member], [Role::Reviewer]]);

it('requires sign-in, a confirmed email and an active account', function () {
    // Given no one is signed in
    // When a topic is created
    // Then it is 401
    $this->postJson('/api/v1/admin/topics', ['slug' => 'x1', 'name' => 'X'])->assertUnauthorized();

    // And an unconfirmed admin is told to confirm, a suspended one is blocked
    Sanctum::actingAs(User::factory()->role(Role::Admin)->unverified()->create());
    $this->postJson('/api/v1/admin/topics', ['slug' => 'x1', 'name' => 'X'])->assertForbidden()->assertJsonPath('code', 'email_unverified');
    Sanctum::actingAs(User::factory()->role(Role::Admin)->suspended()->create());
    $this->postJson('/api/v1/admin/topics', ['slug' => 'x1', 'name' => 'X'])->assertForbidden()->assertJsonPath('code', 'account_unavailable');
});

it('turns a slug race into a 422, not a 500', function () {
    // Given a topic created between validation and insert (the action is called directly, skipping the request rule)
    $admin = User::factory()->role(Role::Admin)->create();
    Topic::factory()->create(['slug' => 'raced']);

    // When the action inserts the same slug
    $call = fn () => app(CreateTopic::class)->handle($admin, ['slug' => 'raced', 'name' => 'Raced']);

    // Then a validation error on slug is thrown, not a query exception
    expect($call)->toThrow(ValidationException::class);
});

it('falls back to a valid position when none is given, and never exceeds 1000', function () {
    // Given an admin and an empty table
    signedInStaff(Role::Admin);

    // When topics are created without a position, and after one sits at 1000
    $first = $this->postJson('/api/v1/admin/topics', ['slug' => 'one', 'name' => 'One']);
    Topic::factory()->create(['position' => 1000]);
    $last = $this->postJson('/api/v1/admin/topics', ['slug' => 'two', 'name' => 'Two']);

    // Then the first is 0 and the next is capped at 1000
    $first->assertCreated()->assertJsonPath('data.position', 0);
    $last->assertCreated()->assertJsonPath('data.position', 1000);
});

it('throttles topic writes with the admin-write limiter', function () {
    // Given an admin who has used the 30 writes allowed per minute
    signedInStaff(Role::Admin);
    $topic = Topic::factory()->create();
    foreach (range(1, 30) as $i) {
        $this->patchJson("/api/v1/admin/topics/{$topic->id}", ['name' => 'Name '.$i])->assertStatus(200);
    }

    // When they write once more
    $response = $this->patchJson("/api/v1/admin/topics/{$topic->id}", ['name' => 'Over']);

    // Then they are throttled
    $response->assertStatus(429);
});
