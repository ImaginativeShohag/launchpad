<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\DisposableEmailRule;
use App\Enums\RiskSignalKind;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;

function antiCheatR5(array $users, ?RuleConfig $config = null): array
{
    return antiCheatFindings(new DisposableEmailRule, antiCheatUserScan(...$users), $config);
}

function antiCheatMemberAt(string $email, array $attributes = []): User
{
    return User::factory()->create(['email' => $email, ...$attributes]);
}

it('matches an exact domain and a subdomain of a list entry, and names the entry', function () {
    // Given members on the domain, on a subdomain, and on an unrelated one
    antiCheatFreeze();
    $exact = antiCheatMemberAt('someone@mailinator.com');
    $sub = antiCheatMemberAt('someone@a.b.mailinator.com');
    $clean = antiCheatMemberAt('someone@example.test');

    // When the rule runs
    $findings = collect(antiCheatR5([$exact, $sub, $clean]))->keyBy('userId');

    // Then the first two fire with the list entry as evidence and the frozen weight
    expect($findings->keys()->sort()->values()->all())->toBe(collect([$exact->id, $sub->id])->sort()->values()->all());
    expect($findings[$exact->id]->kind)->toBe(RiskSignalKind::DisposableEmail)->and($findings[$exact->id]->weight)->toBe(25)->and($findings[$exact->id]->dedupeKey)->toBe('disposable_email:'.$exact->id);
    expect($findings[$sub->id]->details)->toMatchArray(['rule' => 'disposable_email', 'matched_domain' => 'mailinator.com', 'observed' => 1]);
});

it('does not match look-alikes', function (string $email) {
    // Given a member on a look-alike domain
    antiCheatFreeze();
    $user = antiCheatMemberAt($email);

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR5([$user]))->toBe([]);
})->with([
    'prefix glued on' => ['someone@notmailinator.com'],
    'list entry as a label of another domain' => ['someone@mailinator.com.evil.org'],
    'suffix glued on' => ['someone@mailinator.community'],
    'local part holds the domain' => ['mailinator.com@example.test'],
]);

it('stores no e-mail address in the evidence', function () {
    // Given a member on a listed domain
    antiCheatFreeze();
    $user = antiCheatMemberAt('very.secret.person@mailinator.com');

    // When the rule runs
    $json = json_encode(array_map(fn ($f) => [$f->details, $f->dedupeKey], antiCheatR5([$user])));

    // Then neither the address nor its local part appears
    expect($json)->not->toContain('secret')->not->toContain('@');
});

it('selects only the domain, never the address', function () {
    // Given a member and a loaded config
    antiCheatFreeze();
    $user = antiCheatMemberAt('someone@mailinator.com');
    $config = antiCheatConfig();

    // When the rule runs under the query recorder
    $queries = antiCheatQueries(fn () => antiCheatR5([$user], $config));

    // Then the only query reads split_part(email) and no bare e-mail column
    expect($queries)->toHaveCount(1);
    expect($queries[0])->toContain("substring(email from '@([^@]*)$')")->not->toContain('select *')->not->toContain('id, email');
});

it('follows an edited list, is silent for an empty list and for weight 0', function () {
    // Given a member on a domain that is not in the default list
    antiCheatFreeze();
    $user = antiCheatMemberAt('someone@burner.example');

    // When the list gains the domain, then the stored list is emptied, then the weight goes to 0
    $added = antiCheatR5([$user], antiCheatConfig(['anticheat.disposable_domains' => 'burner.example']));
    antiCheatConfig(['anticheat.disposable_domains' => 'mailinator.com']);
    Setting::query()->where('key', 'anticheat.disposable_domains')->update(['value' => json_encode('')]);
    Cache::flush();
    $empty = antiCheatR5([antiCheatMemberAt('someone@mailinator.com')], RuleConfig::fromSettings(app(Settings::class)));
    $off = antiCheatR5([antiCheatMemberAt('x@mailinator.com')], antiCheatConfig(['anticheat.weight.disposable_email' => 0]));

    // Then the edit takes effect and the other two are silent
    expect($added)->toHaveCount(1)->and($added[0]->details['matched_domain'])->toBe('burner.example');
    expect($empty)->toBe([])->and($off)->toBe([]);
});

it('skips a deleted member', function () {
    // Given a deleted member on a listed domain
    antiCheatFreeze();
    $user = antiCheatMemberAt('someone@mailinator.com', ['deleted_at' => antiCheatNow()]);

    // When the rule runs
    // Then nothing fires
    expect(antiCheatR5([$user]))->toBe([]);
});

it('takes the domain after the last @, so a local part with an @ cannot hide or fake one', function () {
    // Given an odd local part that holds a listed domain before the last @, and one that hides it
    antiCheatFreeze();
    $hiding = antiCheatMemberAt('x@mailinator.com@example.test');
    $odd = antiCheatMemberAt('we@ird@mailinator.com');

    // When the rule runs
    $findings = collect(antiCheatR5([$hiding, $odd]));

    // Then only the member whose last @ is followed by the listed domain is found
    expect($findings->pluck('userId')->all())->toBe([$odd->id]);
});
