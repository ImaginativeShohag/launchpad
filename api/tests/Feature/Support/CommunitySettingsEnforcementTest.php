<?php

use App\Actions\Report\CreateReport;
use App\Enums\ReportReason;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Exceptions\CommunityRuleViolation;
use App\Models\Comment;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Database\Seeders\SettingSeeder;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

require_once __DIR__.'/../../Support/CommentFixtures.php';

beforeEach(fn () => $this->travelTo(new DateTimeImmutable('2026-10-21 12:00:00 UTC')));

/** @return list<SettingKey> */
function communitySettingKeys(): array
{
    return array_values(array_filter(SettingKey::cases(), fn (SettingKey $key) => str_starts_with($key->value, 'community.')));
}

/**
 * For each community key, what it changes in real behaviour. A key without an entry here fails the last test below: a
 * setting that nothing enforces is a silent gap (the checklist "setting exists, enforcement exists?").
 *
 * @return array<string, Closure(Settings, User): void>
 */
function communityEnforcement(): array
{
    return [
        'community.comment_edit_minutes' => function (Settings $settings, User $admin): void {
            $author = User::factory()->create();
            $comment = Comment::factory()->createdAt(now()->subMinutes(20))->create(['product_id' => commentable()->id, 'user_id' => $author->id, 'body' => 'Original text']);
            DB::table('products')->where('id', $comment->product_id)->update(['comments_count' => 1]);
            Sanctum::actingAs($author);

            // 20 minutes after writing, the default 15 refuses, 30 allows, 0 refuses even a brand new comment
            test()->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'Edit at twenty'])->assertStatus(409)->assertJsonPath('code', 'edit_window_closed');
            $settings->set(SettingKey::CommunityCommentEditMinutes, 30, $admin);
            test()->patchJson("/api/v1/comments/{$comment->id}", ['body' => 'Edit at twenty'])->assertOk();
            $settings->set(SettingKey::CommunityCommentEditMinutes, 0, $admin);
            $fresh = Comment::factory()->create(['product_id' => $comment->product_id, 'user_id' => $author->id, 'body' => 'Brand new']);
            test()->patchJson("/api/v1/comments/{$fresh->id}", ['body' => 'Immediate edit'])->assertStatus(409)->assertJsonPath('code', 'edit_window_closed');
        },
        'community.report_open_cap' => function (Settings $settings, User $admin): void {
            $reporter = User::factory()->create();
            $create = fn () => app(CreateReport::class)->handle($reporter, 'comment', Comment::factory()->create(['product_id' => commentable()->id])->id, ReportReason::Spam, null);

            // With the cap at 2 the third report is refused; raising it to 3 lets the third through
            $settings->set(SettingKey::CommunityReportOpenCap, 2, $admin);
            $create();
            $create();
            expect($create)->toThrow(CommunityRuleViolation::class);
            $settings->set(SettingKey::CommunityReportOpenCap, 3, $admin);
            expect($create()['created'])->toBeTrue();
        },
    ];
}

it('has a community setting key with its default seeded and valid under its own rules, for every key', function () {
    // Given the seeded settings
    $this->seed(SettingSeeder::class);

    // When every community.* key is inspected
    // Then it is seeded with its default, and the default passes the key's own validation
    expect(communitySettingKeys())->not->toBeEmpty();
    foreach (communitySettingKeys() as $key) {
        expect(Setting::query()->find($key->value)?->value)->toBe($key->default(), $key->value);
        expect(validator(['value' => $key->default()], ['value' => $key->rules()])->passes())->toBeTrue($key->value);
        expect($key->intRange())->toHaveCount(2);
    }
});

it('enforces every community setting: changing it changes real behaviour', function (string $key) {
    // Given an admin who can change the setting
    $admin = User::factory()->role(Role::Admin)->create();

    // When the enforcement scenario of that key runs
    communityEnforcement()[$key](app(Settings::class), $admin);

    // Then it passed (its assertions are the proof)
    expect(true)->toBeTrue();
})->with(array_map(fn (SettingKey $key) => $key->value, communitySettingKeys()));

it('fails when a community key has no enforcement scenario', function () {
    // Given the community keys and the scenarios
    // When they are compared
    // Then every key has a scenario and no scenario is orphaned (a new key must come with proof that something enforces it)
    expect(array_keys(communityEnforcement()))->toEqualCanonicalizing(array_map(fn (SettingKey $key) => $key->value, communitySettingKeys()));
});
