<?php

use App\Actions\Account\UpdateProfile;
use App\Models\User;
use App\Models\UserLink;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

it('requires sign-in to read /me', function () {
    // Given no one is signed in
    // When /me is requested
    // Then it is a JSON 401
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('shows the signed-in member their own account', function () {
    // Given a signed-in member with a link
    $user = User::factory()->create(['email' => 'amina@example.com']);
    $user->links()->create(['kind' => 'github', 'label' => null, 'url' => 'https://github.com/amina', 'position' => 0]);
    Sanctum::actingAs($user);

    // When they read /me
    $response = $this->getJson('/api/v1/me');

    // Then they see their email, flags and links
    $response->assertOk()
        ->assertJsonPath('data.email', 'amina@example.com')
        ->assertJsonPath('data.email_verified', true)
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.pending_email', null)
        ->assertJsonPath('data.links.0.kind', 'github');
});

it('lets an unconfirmed member read /me but not a suspended one', function () {
    // Given an unconfirmed member and a suspended one
    Sanctum::actingAs(User::factory()->unverified()->create());
    $unconfirmed = $this->getJson('/api/v1/me');
    Sanctum::actingAs(User::factory()->suspended()->create());

    // When the suspended member reads /me
    $suspended = $this->getJson('/api/v1/me');

    // Then the first is allowed and the second is blocked
    $unconfirmed->assertOk()->assertJsonPath('data.email_verified', false);
    $suspended->assertForbidden();
});

it('updates name, handle and headline', function () {
    // Given a verified member
    $user = User::factory()->create(['handle' => 'old_handle']);
    Sanctum::actingAs($user);

    // When they edit their profile
    $response = $this->patchJson('/api/v1/me', ['name' => 'Amina R.', 'handle' => 'New_Handle', 'headline' => 'I build things']);

    // Then the changes are saved, with the handle lowercased
    $response->assertOk()->assertJsonPath('data.handle', 'new_handle');
    expect($user->fresh()->name)->toBe('Amina R.');
    expect($user->fresh()->headline)->toBe('I build things');
});

it('cleans text input: strips control characters and trims', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they send names with control characters and padding
    $response = $this->patchJson('/api/v1/me', [
        'name' => "  Amina\u{0000}\u{0007} R.\n ",
        'headline' => "  Maker\r\nof things\u{2028} ",
    ]);

    // Then the stored text is clean
    $response->assertOk();
    expect($user->fresh()->name)->toBe('Amina R.');
    expect($user->fresh()->headline)->toBe('Makerof things');
});

it('clears the headline with null or whitespace', function () {
    // Given a member with a headline
    $user = User::factory()->create(['headline' => 'Maker']);
    Sanctum::actingAs($user);

    // When they send null, then whitespace only
    $this->patchJson('/api/v1/me', ['headline' => null])->assertOk();
    $user->forceFill(['headline' => 'Maker'])->save();
    $this->patchJson('/api/v1/me', ['headline' => " \t "])->assertOk();

    // Then the headline is null, never an empty string
    expect($user->fresh()->headline)->toBeNull();
});

it('lets a member keep their own handle but not take another member’s', function () {
    // Given two members
    $user = User::factory()->create(['handle' => 'amina_r']);
    User::factory()->create(['handle' => 'rafi_k']);
    Sanctum::actingAs($user);

    // When they resubmit their own handle, and when they ask for the other member's
    $own = $this->patchJson('/api/v1/me', ['handle' => 'amina_r']);
    $taken = $this->patchJson('/api/v1/me', ['handle' => 'RAFI_K']);

    // Then the first is fine and the second is refused
    $own->assertOk();
    $taken->assertUnprocessable()->assertJsonValidationErrors('handle');
});

it('refuses reserved and malformed handles', function (string $handle) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they ask for a bad handle
    $response = $this->patchJson('/api/v1/me', ['handle' => $handle]);

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors('handle');
})->with(['reserved' => ['admin'], 'too short' => ['ab'], 'spaces' => ['a b c'], 'dash' => ['a-b-c']]);

it('blocks unconfirmed members from editing their profile', function () {
    // Given a member who has not confirmed their email
    Sanctum::actingAs(User::factory()->unverified()->create());

    // When they try to edit
    $response = $this->patchJson('/api/v1/me', ['name' => 'Nope']);

    // Then they are told to confirm first
    $response->assertForbidden()->assertJsonPath('code', 'email_unverified');
});

it('requires sign-in to edit the profile', function () {
    // Given no one is signed in
    // When they try to edit
    // Then it is a 401
    $this->patchJson('/api/v1/me', ['name' => 'Nope'])->assertUnauthorized();
});

it('blocks suspended members from editing their profile', function () {
    // Given a suspended member
    Sanctum::actingAs(User::factory()->suspended()->create());

    // When they try to edit
    // Then they are blocked
    $this->patchJson('/api/v1/me', ['name' => 'Nope'])->assertForbidden();
});

it('ignores fields a member must not set', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they also send role, email and suspension fields
    $this->patchJson('/api/v1/me', ['name' => 'Fine', 'role' => 'admin', 'email' => 'x@example.com', 'risk_score' => 0, 'avatar_path' => 'avatars/x.webp'])
        ->assertOk();

    // Then those are untouched
    $fresh = $user->fresh();
    expect($fresh->role->value)->toBe('member')->and($fresh->email)->toBe($user->email)->and($fresh->avatar_path)->toBeNull();
});

it('replaces the whole list of links', function () {
    // Given a member with two links
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $this->patchJson('/api/v1/me', ['links' => [
        ['kind' => 'website', 'label' => 'Blog', 'url' => 'https://amina.dev'],
        ['kind' => 'x', 'url' => 'https://x.com/amina'],
    ]])->assertOk()->assertJsonCount(2, 'data.links');

    // When they send a single link
    $response = $this->patchJson('/api/v1/me', ['links' => [['kind' => 'github', 'url' => 'https://github.com/amina']]]);

    // Then only that link remains, at position 0
    $response->assertOk()->assertJsonCount(1, 'data.links')->assertJsonPath('data.links.0.position', 0);
    expect($user->links()->count())->toBe(1);
});

it('keeps the submitted order and cleans labels', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they send three links, the label of one with control characters
    $response = $this->patchJson('/api/v1/me', ['links' => [
        ['kind' => 'x', 'url' => 'https://x.com/a'],
        ['kind' => 'website', 'label' => " My\u{0000} blog ", 'url' => 'https://a.dev'],
        ['kind' => 'github', 'label' => '  ', 'url' => 'https://github.com/a'],
    ]]);

    // Then positions follow the order and labels are clean or null
    $response->assertOk()
        ->assertJsonPath('data.links.0.kind', 'x')->assertJsonPath('data.links.0.position', 0)
        ->assertJsonPath('data.links.1.label', 'My blog')->assertJsonPath('data.links.1.position', 1)
        ->assertJsonPath('data.links.2.label', null)->assertJsonPath('data.links.2.position', 2);
});

it('removes all links with an empty list and leaves them alone when links is absent', function () {
    // Given a member with a link
    $user = User::factory()->create();
    $user->links()->create(['kind' => 'github', 'label' => null, 'url' => 'https://github.com/a', 'position' => 0]);
    Sanctum::actingAs($user);

    // When they update only their name, then send an empty list
    $this->patchJson('/api/v1/me', ['name' => 'Same'])->assertOk()->assertJsonCount(1, 'data.links');
    $this->patchJson('/api/v1/me', ['links' => []])->assertOk()->assertJsonCount(0, 'data.links');

    // Then the link survived the first and was removed by the second
    expect($user->links()->count())->toBe(0);
});

it('accepts https links with ASCII or punycode hosts', function (string $url) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they save the link
    $response = $this->patchJson('/api/v1/me', ['links' => [['kind' => 'website', 'url' => $url]]]);

    // Then it is accepted
    $response->assertOk();
})->with([
    'plain' => ['https://amina.dev'],
    'path and query' => ['https://github.com/amina/repo?tab=readme#top'],
    'punycode' => ['https://xn--mnchen-3ya.de/'],
    'subdomain' => ['https://blog.amina.example.co.uk/a'],
    'explicit 443' => ['https://amina.dev:443/'],
]);

it('rejects unsafe or malformed links and keeps the old list', function (mixed $links) {
    // Given a verified member with one link
    $user = User::factory()->create();
    $user->links()->create(['kind' => 'github', 'label' => null, 'url' => 'https://github.com/a', 'position' => 0]);
    Sanctum::actingAs($user);

    // When they submit bad links
    $response = $this->patchJson('/api/v1/me', ['links' => $links]);

    // Then the request is refused, never a server error, and nothing changed
    $response->assertUnprocessable();
    expect($user->links()->count())->toBe(1);
})->with([
    'http not https' => [[['kind' => 'website', 'url' => 'http://amina.dev']]],
    'javascript scheme' => [[['kind' => 'website', 'url' => 'javascript:alert(1)']]],
    'data scheme' => [[['kind' => 'website', 'url' => 'data:text/html;base64,PHNjcmlwdD4=']]],
    'unknown kind' => [[['kind' => 'myspace', 'url' => 'https://myspace.com/x']]],
    'six links' => [array_fill(0, 6, ['kind' => 'other', 'url' => 'https://example.com'])],
    'missing url' => [[['kind' => 'other']]],
    'missing kind' => [[['url' => 'https://example.com']]],
    'credentials' => [[['kind' => 'website', 'url' => 'https://user:pass@example.com']]],
    'username only' => [[['kind' => 'website', 'url' => 'https://user@example.com']]],
    'no host' => [[['kind' => 'website', 'url' => 'https:///path']]],
    'ipv4' => [[['kind' => 'website', 'url' => 'https://127.0.0.1/x']]],
    'ipv4 private' => [[['kind' => 'website', 'url' => 'https://10.0.0.5/x']]],
    'ipv4 short form' => [[['kind' => 'website', 'url' => 'https://127.1/x']]],
    'ipv6' => [[['kind' => 'website', 'url' => 'https://[::1]/x']]],
    'localhost' => [[['kind' => 'website', 'url' => 'https://localhost/x']]],
    'localhost subdomain' => [[['kind' => 'website', 'url' => 'https://app.localhost/x']]],
    'single label' => [[['kind' => 'website', 'url' => 'https://intranet/x']]],
    'internal suffix' => [[['kind' => 'website', 'url' => 'https://db.internal/x']]],
    'unicode host' => [[['kind' => 'website', 'url' => 'https://münchen.de/']]],
    'unicode path' => [[['kind' => 'website', 'url' => 'https://example.com/ü']]],
    'backslash' => [[['kind' => 'website', 'url' => 'https://example.com\\@evil.com']]],
    'space' => [[['kind' => 'website', 'url' => 'https://exa mple.com']]],
    'odd port' => [[['kind' => 'website', 'url' => 'https://example.com:8443/']]],
    'trailing dot' => [[['kind' => 'website', 'url' => 'https://example.com./']]],
    'empty label' => [[['kind' => 'website', 'url' => 'https://a..com/']]],
    'too long' => [[['kind' => 'website', 'url' => 'https://example.com/'.str_repeat('a', 2040)]]],
    'label too long' => [[['kind' => 'website', 'label' => str_repeat('a', 41), 'url' => 'https://example.com']]],
    'links is a string' => ['https://example.com'],
    'links is an object' => [['a' => ['kind' => 'website', 'url' => 'https://example.com']]],
    'links is null' => [null],
    'links is a number' => [5],
    'entry is a string' => [['https://example.com']],
    'entry is null' => [[null]],
    'entry is a nested list' => [[[['kind' => 'website', 'url' => 'https://example.com']]]],
    'kind is an array' => [[['kind' => ['website'], 'url' => 'https://example.com']]],
    'kind is null' => [[['kind' => null, 'url' => 'https://example.com']]],
    'kind is a number' => [[['kind' => 1, 'url' => 'https://example.com']]],
    'url is an array' => [[['kind' => 'website', 'url' => ['https://example.com']]]],
    'url is a number' => [[['kind' => 'website', 'url' => 5]]],
    'url is null' => [[['kind' => 'website', 'url' => null]]],
    'label is an array' => [[['kind' => 'website', 'label' => ['x'], 'url' => 'https://example.com']]],
    'label is an object' => [[['kind' => 'website', 'label' => ['a' => 'b'], 'url' => 'https://example.com']]],
]);

it('answers 422, never 500, for odd scalar and nested payloads', function (array $payload) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they send odd values for the text fields
    $response = $this->patchJson('/api/v1/me', $payload);

    // Then it is a validation error
    $response->assertUnprocessable();
})->with([
    'name array' => [['name' => ['a']]],
    'name object' => [['name' => ['a' => ['b' => 'c']]]],
    'name empty array' => [['name' => []]],
    'name null' => [['name' => null]],
    'name number' => [['name' => 123]],
    'name bool' => [['name' => true]],
    'name whitespace' => [['name' => "   \t "]],
    'name control only' => [['name' => "\u{0000}\u{0007}"]],
    'name too long' => [['name' => str_repeat('a', 81)]],
    'handle array' => [['handle' => ['abc']]],
    'handle object' => [['handle' => ['a' => 'abc']]],
    'handle empty array' => [['handle' => []]],
    'handle null' => [['handle' => null]],
    'handle number' => [['handle' => 12345]],
    'headline array' => [['headline' => ['a']]],
    'headline object' => [['headline' => ['a' => 'b']]],
    'headline number' => [['headline' => 5]],
    'headline too long' => [['headline' => str_repeat('a', 121)]],
]);

it('rejects text that is not valid UTF-8', function () {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When a form post carries invalid UTF-8 bytes
    $response = $this->patch('/api/v1/me', ['name' => "bad\xFF\xFEname", 'headline' => "x\xC3"], ['Accept' => 'application/json']);

    // Then it is a validation error, not a database error
    $response->assertUnprocessable()->assertJsonValidationErrors(['name', 'headline']);
});

it('strips bidi and zero-width characters but keeps ZWNJ and ZWJ', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $persian = "می\u{200C}خواهم";
    $emoji = "👩\u{200D}💻";

    // When they send spoofing characters mixed into text, and legitimate joiners
    $this->patchJson('/api/v1/me', [
        'name' => "Amina\u{202E}gpj.exe\u{200B}\u{2060}\u{FEFF}\u{200E}\u{200F}\u{061C}\u{2066}\u{2069}",
        'headline' => "Maker\u{202A}\u{202B}\u{202C}\u{202D}\u{202E} {$persian} {$emoji}",
        'links' => [['kind' => 'website', 'label' => "Blog\u{202E}", 'url' => 'https://amina.dev']],
    ])->assertOk();

    // Then spoofing characters are gone and joiners survive
    $fresh = $user->fresh();
    expect($fresh->name)->toBe('Aminagpj.exe');
    expect($fresh->headline)->toBe("Maker {$persian} {$emoji}");
    expect($fresh->links()->first()->label)->toBe('Blog');
});

it('keeps Bengali text intact', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they use a Bengali name with combining marks
    $this->patchJson('/api/v1/me', ['name' => 'আমিনা রহমান', 'headline' => 'আমি একজন নির্মাতা'])->assertOk();

    // Then it is stored unchanged
    expect($user->fresh()->name)->toBe('আমিনা রহমান')->and($user->fresh()->headline)->toBe('আমি একজন নির্মাতা');
});

it('refuses names that are invisible or have no letter or number', function (string $name) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they submit it
    // Then it is refused
    $this->patchJson('/api/v1/me', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
})->with([
    'zero width only' => ["\u{200B}\u{200B}\u{200B}"],
    'bidi only' => ["\u{202E}\u{2066}"],
    'bom and word joiner' => ["\u{FEFF}\u{2060}"],
    'joiners only' => ["\u{200C}\u{200D}"],
    'punctuation only' => ['!!! ...'],
    'emoji only' => ['😀'],
]);

it('lowercases the scheme and host of a link but keeps path and query case', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they send a link with mixed case
    $this->patchJson('/api/v1/me', ['links' => [['kind' => 'website', 'url' => ' HtTpS://Example.COM/Path/Ab?Q=Xy#Frag ']]])->assertOk();

    // Then it is stored normalised
    expect($user->links()->first()->url)->toBe('https://example.com/Path/Ab?Q=Xy#Frag');
});

it('rejects hex and numeric IP hosts through the endpoint', function (string $url) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When they submit it
    // Then it is refused
    $this->patchJson('/api/v1/me', ['links' => [['kind' => 'website', 'url' => $url]]])->assertUnprocessable();
})->with(['https://0x7f.0x0.0x0.0x1', 'https://0x7f.1', 'https://017700000001', 'https://2130706433', 'https://a.b']);

it('locks the member row while updating, to serialise concurrent link replacements', function () {
    // Given a verified member and a query log
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    // When they replace their links
    $this->patchJson('/api/v1/me', ['links' => [['kind' => 'github', 'url' => 'https://github.com/a']]])->assertOk();

    // Then the users row was selected FOR UPDATE before the links were touched
    $lock = collect($queries)->search(fn ($sql) => str_contains($sql, 'from "users"') && str_contains($sql, 'for update'));
    $delete = collect($queries)->search(fn ($sql) => str_contains($sql, 'delete from "user_links"'));
    expect($lock)->not->toBeFalse()->and($delete)->not->toBeFalse()->and($lock)->toBeLessThan($delete);
});

it('does not blame the handle for an unrelated unique violation', function (array $extra) {
    // Given a member, and a link row that appears at position 0 between the delete and the insert
    $user = User::factory()->create(['handle' => 'amina_r']);
    Sanctum::actingAs($user);
    $fired = false;
    UserLink::creating(function () use (&$fired, $user) {
        if (! $fired) {
            $fired = true;
            DB::table('user_links')->insert([
                'id' => (string) Str::ulid(), 'user_id' => $user->id, 'kind' => 'other',
                'url' => 'https://racing.example.com', 'position' => 0,
            ]);
        }
    });

    // When they save links (with or without a handle that nobody else has)
    $response = $this->patchJson('/api/v1/me', ['links' => [['kind' => 'github', 'url' => 'https://github.com/a']], ...$extra]);

    // Then it is a server error, not a bogus "handle taken" validation error
    $response->assertStatus(500)->assertJsonMissingValidationErrors('handle');
    UserLink::flushEventListeners();
})->with(['no handle' => [[]], 'unchanged handle' => [['handle' => 'amina_r']], 'free new handle' => [['handle' => 'brand_new']]]);

it('still reports a handle taken between validation and the write as a handle error', function () {
    // Given a member whose chosen handle is taken by someone else just before the write
    $user = User::factory()->create(['handle' => 'amina_r']);
    Sanctum::actingAs($user);
    // (The racer is inserted just before the action's own transaction starts, so it survives the rollback.)
    app()->bind(UpdateProfile::class, fn () => new class extends UpdateProfile
    {
        public function handle(User $user, array $data): User
        {
            DB::table('users')->insert([
                'id' => (string) Str::ulid(), 'handle' => 'wanted_one', 'name' => 'Racer', 'email' => 'racer@example.com',
            ]);

            return parent::handle($user, $data);
        }
    });

    // When they ask for that handle
    $response = $this->patchJson('/api/v1/me', ['handle' => 'Wanted_One']);

    // Then it is a handle validation error
    $response->assertUnprocessable()->assertJsonValidationErrors('handle');
    expect($user->fresh()->handle)->toBe('amina_r');
});

dataset('invisible characters', [
    'hangul choseong filler' => ["\u{115F}"],
    'hangul jungseong filler' => ["\u{1160}"],
    'hangul filler' => ["\u{3164}"],
    'halfwidth hangul filler' => ["\u{FFA0}"],
    'soft hyphen' => ["\u{00AD}"],
    'combining grapheme joiner' => ["\u{034F}"],
    'mongolian selector' => ["\u{180B}\u{180C}\u{180D}\u{180E}"],
    'khmer inherent vowels' => ["\u{17B4}\u{17B5}"],
    'invisible operators' => ["\u{2061}\u{2062}\u{2063}\u{2064}"],
    'deprecated format characters' => ["\u{206A}\u{206F}"],
    'interlinear annotation' => ["\u{FFF9}\u{FFFA}\u{FFFB}"],
    'object replacement' => ["\u{FFFC}"],
    'braille blank' => ["\u{2800}"],
    'tag characters' => ["\u{E0041}\u{E007F}"],
]);

it('refuses a name made only of invisible characters', function (string $invisible) {
    // Given a verified member
    Sanctum::actingAs(User::factory()->create());

    // When the name is only that character, repeated
    $response = $this->patchJson('/api/v1/me', ['name' => $invisible.$invisible.$invisible]);

    // Then it is refused
    $response->assertUnprocessable()->assertJsonValidationErrors('name');
})->with('invisible characters');

it('stores an invisible-only headline and link label as null', function (string $invisible) {
    // Given a member with a headline
    $user = User::factory()->create(['headline' => 'Maker']);
    Sanctum::actingAs($user);

    // When headline and label contain only that character
    $this->patchJson('/api/v1/me', [
        'headline' => $invisible,
        'links' => [['kind' => 'website', 'label' => $invisible, 'url' => 'https://amina.dev']],
    ])->assertOk();

    // Then both are null
    expect($user->fresh()->headline)->toBeNull();
    expect($user->links()->first()->label)->toBeNull();
})->with('invisible characters');

it('strips a soft hyphen from inside a real name', function () {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When the name has a soft hyphen inside
    $this->patchJson('/api/v1/me', ['name' => "Ami\u{00AD}na"])->assertOk();

    // Then it is stored without it
    expect($user->fresh()->name)->toBe('Amina');
});

it('leaves legitimate scripts and emoji sequences unchanged', function (string $text) {
    // Given a verified member
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    // When they use it as name and headline
    $this->patchJson('/api/v1/me', ['name' => $text, 'headline' => $text])->assertOk();

    // Then it is stored unchanged
    expect($user->fresh()->name)->toBe($text)->and($user->fresh()->headline)->toBe($text);
})->with([
    'persian with zwnj' => ["می\u{200C}خواهم"],
    'bengali' => ['আমিনা রহমান'],
    'devanagari' => ['अमीना शर्मा'],
    'family emoji' => ["Fam 👨\u{200D}👩\u{200D}👧"],
    'rainbow flag' => ["Pride 🏳\u{FE0F}\u{200D}🌈"],
    'variation selector 16' => ["Heart ❤\u{FE0F}"],
]);
