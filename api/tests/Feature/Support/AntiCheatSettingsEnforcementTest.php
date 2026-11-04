<?php

use App\Actions\AntiCheat\FlagVotes;
use App\Actions\AntiCheat\ProductIntegrity;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\CoordinatedVotesRule;
use App\Actions\AntiCheat\Rules\DisposableEmailRule;
use App\Actions\AntiCheat\Rules\FreshAccountRule;
use App\Actions\AntiCheat\Rules\GroupedVoteRule;
use App\Actions\AntiCheat\Rules\IpClusterRule;
use App\Actions\AntiCheat\Rules\LoginChurnRule;
use App\Actions\AntiCheat\Rules\ProductBurstRule;
use App\Actions\AntiCheat\Rules\Rule;
use App\Actions\AntiCheat\Rules\SharedDeviceRule;
use App\Actions\AntiCheat\Rules\SingleMakerVoterRule;
use App\Actions\AntiCheat\Rules\UserBurstRule;
use App\Actions\AntiCheat\Scan;
use App\Actions\AntiCheat\ScoreUsers;
use App\Actions\Retention\PurgeRiskSignals;
use App\Enums\RiskOutcome;
use App\Enums\RiskSignalKind;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Enums\VoteStatus;
use App\Models\RiskSignal;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/RetentionFixtures.php';

/**
 * Every anti-cheat setting (and the two risk signal retention windows) with the code that enforces it.
 *
 * `property` is the RuleConfig property the key feeds (null when the key is read straight through Settings), `alt` a valid
 * non-default value, `readers` the classes whose source must use that property (or, for a key read directly, name the key).
 *
 * @return array<string, array{alt: int|string, property: string|null, readers: list<class-string>}>
 */
function antiCheatEnforcementMap(): array
{
    return [
        'anticheat.weight.shared_device' => ['alt' => 7, 'property' => 'weightSharedDevice', 'readers' => [SharedDeviceRule::class]],
        'anticheat.weight.ip_cluster' => ['alt' => 7, 'property' => 'weightIpCluster', 'readers' => [IpClusterRule::class]],
        'anticheat.weight.vote_burst' => ['alt' => 7, 'property' => 'weightVoteBurst', 'readers' => [UserBurstRule::class]],
        'anticheat.weight.fresh_account' => ['alt' => 7, 'property' => 'weightFreshAccount', 'readers' => [FreshAccountRule::class]],
        'anticheat.weight.disposable_email' => ['alt' => 7, 'property' => 'weightDisposableEmail', 'readers' => [DisposableEmailRule::class]],
        'anticheat.weight.single_maker_voter' => ['alt' => 7, 'property' => 'weightSingleMakerVoter', 'readers' => [SingleMakerVoterRule::class]],
        'anticheat.weight.login_churn' => ['alt' => 7, 'property' => 'weightLoginChurn', 'readers' => [LoginChurnRule::class]],
        'anticheat.weight.coordinated_votes' => ['alt' => 7, 'property' => 'weightCoordinatedVotes', 'readers' => [CoordinatedVotesRule::class]],
        'anticheat.group_window_hours' => ['alt' => 12, 'property' => 'groupWindowHours', 'readers' => [SharedDeviceRule::class, IpClusterRule::class, LoginChurnRule::class, GroupedVoteRule::class]],
        'anticheat.shared_device.min_accounts' => ['alt' => 4, 'property' => 'sharedDeviceMinAccounts', 'readers' => [SharedDeviceRule::class]],
        'anticheat.ip_cluster.min_accounts' => ['alt' => 6, 'property' => 'ipClusterMinAccounts', 'readers' => [IpClusterRule::class]],
        'anticheat.ip_cluster.established_days' => ['alt' => 10, 'property' => 'ipClusterEstablishedDays', 'readers' => [GroupedVoteRule::class]],
        'anticheat.ip_cluster.discount' => ['alt' => '0.3', 'property' => 'ipClusterDiscount', 'readers' => [IpClusterRule::class]],
        'anticheat.burst.window_minutes' => ['alt' => 20, 'property' => 'burstWindowMinutes', 'readers' => [ProductBurstRule::class]],
        'anticheat.burst.min_votes' => ['alt' => 40, 'property' => 'burstMinVotes', 'readers' => [ProductBurstRule::class]],
        'anticheat.burst.ratio' => ['alt' => '6', 'property' => 'burstRatio', 'readers' => [ProductBurstRule::class]],
        'anticheat.user_burst.votes' => ['alt' => 9, 'property' => 'userBurstVotes', 'readers' => [UserBurstRule::class]],
        'anticheat.user_burst.minutes' => ['alt' => 7, 'property' => 'userBurstMinutes', 'readers' => [UserBurstRule::class]],
        'anticheat.fresh_account.minutes' => ['alt' => 45, 'property' => 'freshAccountMinutes', 'readers' => [FreshAccountRule::class]],
        'anticheat.disposable_domains' => ['alt' => 'alpha.example,beta.example', 'property' => 'domains', 'readers' => [DisposableEmailRule::class]],
        'anticheat.single_maker.min_votes' => ['alt' => 6, 'property' => 'singleMakerMinVotes', 'readers' => [SingleMakerVoterRule::class]],
        'anticheat.single_maker.days' => ['alt' => 20, 'property' => 'singleMakerDays', 'readers' => [SingleMakerVoterRule::class]],
        'anticheat.single_maker.share' => ['alt' => '0.9', 'property' => 'singleMakerShare', 'readers' => [SingleMakerVoterRule::class]],
        'anticheat.login_churn.min_accounts' => ['alt' => 5, 'property' => 'loginChurnMinAccounts', 'readers' => [LoginChurnRule::class]],
        'anticheat.coordinated.min_accounts' => ['alt' => 4, 'property' => 'coordinatedMinAccounts', 'readers' => [CoordinatedVotesRule::class]],
        'anticheat.coordinated.min_products' => ['alt' => 5, 'property' => 'coordinatedMinProducts', 'readers' => [CoordinatedVotesRule::class]],
        'anticheat.coordinated.overlap' => ['alt' => '0.9', 'property' => 'coordinatedOverlap', 'readers' => [CoordinatedVotesRule::class]],
        'anticheat.coordinated.max_popularity_pct' => ['alt' => 50, 'property' => 'coordinatedMaxPopularityPct', 'readers' => [CoordinatedVotesRule::class]],
        'anticheat.decay_days' => ['alt' => 7, 'property' => 'decayDays', 'readers' => [ScoreUsers::class]],
        'anticheat.flag_risk_score' => ['alt' => 60, 'property' => 'flagScore', 'readers' => [FlagVotes::class]],
        'anticheat.product_watch_ratio' => ['alt' => '0.95', 'property' => null, 'readers' => [Settings::class]],
        'anticheat.product_alert_ratio' => ['alt' => '0.5', 'property' => null, 'readers' => [Settings::class]],
        'retention.risk_signals_days' => ['alt' => 100, 'property' => null, 'readers' => [PurgeRiskSignals::class]],
        'retention.risk_signals_reviewed_days' => ['alt' => 30, 'property' => null, 'readers' => [PurgeRiskSignals::class]],
    ];
}

/** The SettingKey case name of a key value. */
function antiCheatCaseName(string $key): string
{
    return SettingKey::from($key)->name;
}

it('has an entry for every anti-cheat setting and both risk signal retention windows, and no orphan', function () {
    // Given the enum and the map
    $keys = array_values(array_map(
        fn (SettingKey $key) => $key->value,
        array_filter(SettingKey::cases(), fn (SettingKey $key) => str_starts_with($key->value, 'anticheat.') || str_starts_with($key->value, 'retention.risk_signals')),
    ));

    // When they are compared
    // Then a new key must come with its enforcer: the sets are equal, and no key is left without readers
    expect(array_keys(antiCheatEnforcementMap()))->toEqualCanonicalizing($keys);
    foreach (antiCheatEnforcementMap() as $key => $entry) {
        expect($entry['readers'])->not->toBeEmpty($key);
    }
});

it('reads every key in the code that enforces it: the config reads the key, and the enforcing classes use what it feeds', function (string $key) {
    // Given the map entry of the key
    $entry = antiCheatEnforcementMap()[$key];
    $case = antiCheatCaseName($key);

    // When the sources are read
    $source = fn (string $class) => file_get_contents((new ReflectionClass($class))->getFileName());

    // Then a RuleConfig key is read in RuleConfig and used (as a property) by every reader; any other key is named by its reader
    if ($entry['property'] !== null) {
        // (the disposable list is read through `Settings::domains()`, whose default argument names the key)
        expect(str_contains($source(RuleConfig::class), 'SettingKey::'.$case) || ($key === 'anticheat.disposable_domains' && str_contains($source(RuleConfig::class), '->domains(')))->toBeTrue();
        foreach ($entry['readers'] as $reader) {
            $text = $source($reader);
            expect(str_contains($text, '->'.$entry['property']) || str_contains($text, 'SettingKey::'.$case))->toBeTrue($reader);
        }

        return;
    }

    foreach ($entry['readers'] as $reader) {
        expect($source($reader))->toContain('SettingKey::'.$case);
    }
})->with(fn () => array_map(fn ($key) => [$key], array_keys(antiCheatEnforcementMap())));

it('serves the config property its key feeds as soon as an admin changes the setting', function (string $key) {
    // Given the default config and an admin
    $entry = antiCheatEnforcementMap()[$key];
    $admin = User::factory()->role(Role::Admin)->create();
    $before = RuleConfig::fromSettings(app(Settings::class));

    // When the setting is changed to another valid value
    app(Settings::class)->set(SettingKey::from($key), $entry['alt'], $admin);
    $after = RuleConfig::fromSettings(app(Settings::class));

    // Then exactly that property moved, and nothing else (a swapped mapping would move a different one)
    $moved = [];
    foreach (get_object_vars($after) as $name => $value) {
        if ($value !== $before->{$name}) {
            $moved[] = $name;
        }
    }
    expect($moved)->toBe($entry['property'] === null ? ($key === 'anticheat.product_watch_ratio' || $key === 'anticheat.product_alert_ratio' ? ['bands'] : []) : [$entry['property']]);
})->with(fn () => array_map(fn ($key) => [$key], array_keys(array_filter(antiCheatEnforcementMap(), fn ($entry, $key) => ! str_starts_with($key, 'retention.'), ARRAY_FILTER_USE_BOTH))));

it('changes what a rule finds when its threshold, window or weight changes', function () {
    // Given a ring of three on one device that voted 30 hours ago, old accounts (all dates fixed)
    antiCheatFreeze();
    $old = ['created_at' => antiCheatNow()->subDays(20)];
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 3, 'enforce', userAttributes: $old, voteAttributes: ['voted_at' => antiCheatNow()->subHours(30)]);
    $scan = antiCheatProductScan($product);
    $weight = fn (RuleConfig $config) => collect(antiCheatFindings(new SharedDeviceRule, $scan, $config))->map->weight->unique()->values()->all();

    // When the window, the threshold and the weight are changed one at a time
    // Then outside the default 24 hour window nothing fires; a 48 hour window finds the ring; a higher threshold hides it; the weight follows
    // (settings persist between the calls, so each call states the values it needs)
    expect($weight(antiCheatConfig()))->toBe([]);
    expect($weight(antiCheatConfig(['anticheat.group_window_hours' => 48])))->toBe([40]);
    expect($weight(antiCheatConfig(['anticheat.group_window_hours' => 48, 'anticheat.shared_device.min_accounts' => 4])))->toBe([]);
    expect($weight(antiCheatConfig(['anticheat.group_window_hours' => 48, 'anticheat.shared_device.min_accounts' => 3, 'anticheat.weight.shared_device' => 12])))->toBe([12]);
});

it('discounts an established cluster only when the established-days setting says its accounts are old enough', function () {
    // Given a cluster of five accounts that are 20 days old on one IP
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    antiCheatRing($product, 5, 'cluster', 'one-ip', userAttributes: ['created_at' => antiCheatNow()->subDays(20)]);
    $scan = antiCheatProductScan($product);
    $weight = fn (RuleConfig $config) => collect(antiCheatFindings(new IpClusterRule, $scan, $config))->map->weight->unique()->values()->all();

    // When the established age and the discount move
    // Then 20 days is not established at 30 (full 15), is at 10 (halved to 8), and the discount factor scales it
    expect($weight(antiCheatConfig()))->toBe([15]);
    expect($weight(antiCheatConfig(['anticheat.ip_cluster.established_days' => 10])))->toBe([8]);
    expect($weight(antiCheatConfig(['anticheat.ip_cluster.established_days' => 10, 'anticheat.ip_cluster.discount' => '0.2'])))->toBe([3]);
});

it('moves the fresh-account and disposable-address rules with their settings', function () {
    // Given a member who voted 40 minutes after signing up, and one on a custom throwaway domain
    antiCheatFreeze();
    $fresh = User::factory()->create(['created_at' => antiCheatNow()->subMinutes(50)]);
    antiCheatVote($fresh, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subMinutes(10)]);
    $custom = User::factory()->create(['email' => 'x'.Str::lower((string) Str::ulid()).'@throwaway.example', 'created_at' => antiCheatNow()->subDays(20)]);
    antiCheatVote($custom, antiCheatLiveProduct());
    $count = fn (object $rule, RuleConfig $config) => count(antiCheatFindings($rule, antiCheatUserScan($fresh, $custom), $config));

    // When the minutes and the domain list change
    // Then the 40 minute gap counts at 45 minutes and not at 30; the custom domain is caught only once it is on the list
    expect($count(new FreshAccountRule, antiCheatConfig()))->toBe(0);
    expect($count(new FreshAccountRule, antiCheatConfig(['anticheat.fresh_account.minutes' => 45])))->toBe(1);
    expect($count(new DisposableEmailRule, antiCheatConfig()))->toBe(0);
    expect($count(new DisposableEmailRule, antiCheatConfig(['anticheat.disposable_domains' => 'throwaway.example'])))->toBe(1);
    expect($count(new DisposableEmailRule, antiCheatConfig(['anticheat.disposable_domains' => 'throwaway.example', 'anticheat.weight.disposable_email' => 0])))->toBe(0);
});

it('moves the score with the decay window, and the flag decision with the flag score', function () {
    // Given a member with a week-old open signal (weight 40) and a stored score, and a vote today
    antiCheatFreeze();
    $member = User::factory()->create();
    antiCheatSignal($member, RiskSignalKind::SharedDevice, 40, 7);
    $vote = antiCheatVote($member, antiCheatLiveProduct());
    $score = fn () => app(ScoreUsers::class)->forLockedUser($member->id, antiCheatNow());

    // When the decay window changes
    // Then the 7 day old signal keeps half (20) over 14 days and is fully decayed (0) over 7
    expect($score())->toBe(20);
    antiCheatConfig(['anticheat.decay_days' => 7]);
    expect($score())->toBe(0);

    // And with the score stored at 50, the vote flags only once the flag score is lowered to 50 and unflags when raised again
    antiCheatSetScore($member, 50);
    antiCheatFlag();
    expect(antiCheatVoteStatus($vote))->toBe('counted');
    antiCheatConfig(['anticheat.flag_risk_score' => 50]);
    antiCheatFlag();
    expect(antiCheatVoteStatus($vote))->toBe('flagged');
    antiCheatConfig(['anticheat.flag_risk_score' => 51]);
    antiCheatFlag();
    expect(antiCheatVoteStatus($vote))->toBe('counted');
});

it('moves the integrity bands with the watch and alert ratios', function () {
    // Given a product where 4 of 5 active votes are counted (ratio 0.8)
    antiCheatFreeze();
    $product = antiCheatLiveProduct();
    foreach (range(1, 4) as $_) {
        antiCheatVote(null, $product);
    }
    antiCheatVote(null, $product, ['status' => VoteStatus::Flagged]);
    $band = fn () => app(ProductIntegrity::class)->for($product->id, antiCheatNow())['band'];

    // When the ratios change
    // Then 0.8 is a watch (below 0.9, above 0.7), clean once the watch ratio drops to 0.8, and an alert with watch 0.95 and alert 0.85
    expect($band())->toBe('watch');
    antiCheatConfig(['anticheat.product_watch_ratio' => '0.8']);
    expect($band())->toBe('clean');
    antiCheatConfig(['anticheat.product_watch_ratio' => '0.95', 'anticheat.product_alert_ratio' => '0.85']);
    expect($band())->toBe('alert');
});

it('moves the purge windows with the retention settings', function () {
    // Given an unreviewed signal 150 days old and one reviewed 40 days ago (inside the defaults of 365 and 90)
    $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC'));
    $unreviewed = antiCheatPurgeSignal(150);
    $reviewed = antiCheatPurgeSignal(60, RiskOutcome::Confirmed, 40);
    $admin = User::factory()->role(Role::Admin)->create();
    expect(app(PurgeRiskSignals::class)->purge(retentionNow()))->toBe(0);

    // When each window is shortened on its own
    // Then only the row of that window goes
    app(Settings::class)->set(SettingKey::RetentionRiskSignalsDays, 100, $admin);
    expect(app(PurgeRiskSignals::class)->purge(retentionNow()))->toBe(1)->and(DB::table('risk_signals')->where('id', $unreviewed)->exists())->toBeFalse()->and(DB::table('risk_signals')->where('id', $reviewed)->exists())->toBeTrue();
    app(Settings::class)->set(SettingKey::RetentionRiskSignalsReviewedDays, 30, $admin);
    expect(app(PurgeRiskSignals::class)->purge(retentionNow()))->toBe(1)->and(DB::table('risk_signals')->where('id', $reviewed)->exists())->toBeFalse();
});

/**
 * Fixtures for the table below: each builds a small world at the fixed clock where its rule finds something with the defaults,
 * and returns the rule and the scan to run it with.
 *
 * @return array<string, Closure(): array{0: Rule, 1: Scan}>
 */
function antiCheatEnforcementFixtures(): array
{
    $old = ['created_at' => antiCheatNow()->subDays(40)];
    $product = fn () => antiCheatLiveProduct();
    $burst = function (int $count, int $everySeconds, array $history = []) use ($product) {
        $p = $product();
        for ($i = 0; $i < $count; $i++) {
            antiCheatVote(null, $p, ['voted_at' => antiCheatNow()->subSeconds(60 + $i * $everySeconds)]);
        }
        foreach ($history as $hoursBack => $votes) {
            DB::table('product_hourly_stats')->insert(['product_id' => $p->id, 'hour_start' => antiCheatNow()->startOfHour()->subHours($hoursBack), 'votes' => $votes, 'views' => 0]);
        }

        return [new ProductBurstRule, antiCheatProductScan($p)];
    };
    $userBurst = function (int $votes, int $everySeconds) use ($old) {
        $user = User::factory()->create($old);
        for ($i = 0; $i < $votes; $i++) {
            antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subSeconds(10 + $i * $everySeconds)]);
        }

        return [new UserBurstRule, antiCheatUserScan($user)];
    };
    $single = function () use ($old) {
        $maker = User::factory()->create();
        $voter = User::factory()->create($old);
        foreach (range(1, 5) as $i) {
            antiCheatVote($voter, antiCheatLiveProduct(attributes: ['maker_id' => $i <= 4 ? $maker->id : User::factory()->create()->id]), ['voted_at' => antiCheatNow()->subDays(10)]);
        }

        return [new SingleMakerVoterRule, antiCheatUserScan($voter)];
    };
    $coordinated = function () use ($old) {
        $products = array_map(fn () => antiCheatLiveProduct(), range(1, 5));
        foreach (range(1, 3) as $_) {
            $member = User::factory()->create($old);
            RiskSignal::factory()->forUserOnly()->kind(RiskSignalKind::FreshAccount, 15)->create(['user_id' => $member->id, 'detected_at' => antiCheatNow()->subHour()]);
            foreach (array_slice($products, 0, 4) as $p) {
                antiCheatVote($member, $p);
            }
            antiCheatVote($member, antiCheatLiveProduct()); // one private vote: overlap is 4 of 5
        }
        antiCheatConfig(['anticheat.coordinated.max_popularity_pct' => 100]);

        return [new CoordinatedVotesRule, Scan::run(antiCheatNow())];
    };

    return [
        'burst fast' => fn () => $burst(30, 1),
        'burst slow' => fn () => $burst(30, 40),
        'burst history' => fn () => $burst(30, 1, [1 => 10, 2 => 10, 3 => 10]),
        'user burst fast' => fn () => $userBurst(8, 5),
        'user burst slow' => fn () => $userBurst(8, 50),
        'single maker' => $single,
        'login churn' => function () {
            antiCheatChurnWorld();

            return [new LoginChurnRule, Scan::run(antiCheatNow())];
        },
        'coordinated' => $coordinated,
        'ip cluster' => function () use ($product, $old) {
            $p = $product();
            antiCheatRing($p, 5, 'enf-cluster', 'enf-ip', userAttributes: $old);

            return [new IpClusterRule, antiCheatProductScan($p)];
        },
        'shared device' => function () use ($product, $old) {
            $p = $product();
            antiCheatRing($p, 3, 'enf-shared', userAttributes: $old);

            return [new SharedDeviceRule, antiCheatProductScan($p)];
        },
        'fresh account' => function () {
            $user = User::factory()->create(['created_at' => antiCheatNow()->subMinutes(20)]);
            antiCheatVote($user, antiCheatLiveProduct(), ['voted_at' => antiCheatNow()->subMinutes(10)]);

            return [new FreshAccountRule, antiCheatUserScan($user)];
        },
    ];
}

/** Four members who logged in from one device and each voted (the login churn world). */
function antiCheatChurnWorld(): void
{
    foreach (range(1, 4) as $_) {
        $user = User::factory()->create(['created_at' => antiCheatNow()->subDays(40)]);
        antiCheatAuthEvent($user, antiCheatNow()->subHour(), 'enf-churn');
        antiCheatVote($user, antiCheatLiveProduct());
    }
}

it('makes a finding appear or disappear when the setting that governs it moves', function (string $fixture, string $key, int|string $value) {
    // Given a world where the rule finds something with the defaults
    antiCheatFreeze();
    [$rule, $scan] = antiCheatEnforcementFixtures()[$fixture]();
    $count = fn (RuleConfig $config) => count(antiCheatFindings($rule, $scan, $config));
    expect($count(antiCheatConfig()))->toBeGreaterThan(0);

    // When the key is moved to a value past the boundary (a weight of 0 switches the rule off)
    $after = $count(antiCheatConfig([$key => $value]));

    // Then the finding is gone, so the key really is read by the rule
    expect($after)->toBe(0);
})->with([
    ['burst fast', 'anticheat.burst.min_votes', 31],
    ['burst history', 'anticheat.burst.ratio', '20'],
    ['user burst fast', 'anticheat.user_burst.votes', 9],
    ['user burst fast', 'anticheat.weight.vote_burst', 0],
    ['single maker', 'anticheat.single_maker.min_votes', 6],
    ['single maker', 'anticheat.single_maker.share', '0.9'],
    ['single maker', 'anticheat.single_maker.days', 7],
    ['single maker', 'anticheat.weight.single_maker_voter', 0],
    ['login churn', 'anticheat.login_churn.min_accounts', 5],
    ['login churn', 'anticheat.weight.login_churn', 0],
    ['coordinated', 'anticheat.coordinated.min_accounts', 4],
    ['coordinated', 'anticheat.coordinated.min_products', 5],
    ['coordinated', 'anticheat.coordinated.overlap', '0.9'],
    ['coordinated', 'anticheat.weight.coordinated_votes', 0],
    ['ip cluster', 'anticheat.ip_cluster.min_accounts', 6],
    ['ip cluster', 'anticheat.weight.ip_cluster', 0],
    ['shared device', 'anticheat.weight.shared_device', 0],
    ['fresh account', 'anticheat.weight.fresh_account', 0],
]);

it('makes a finding appear when a window setting is widened', function (string $fixture, string $key, int|string $value) {
    // Given a world where the rule finds nothing with the defaults
    antiCheatFreeze();
    [$rule, $scan] = antiCheatEnforcementFixtures()[$fixture]();
    $count = fn (RuleConfig $config) => count(antiCheatFindings($rule, $scan, $config));
    expect($count(antiCheatConfig()))->toBe(0);

    // When the window is widened
    // Then the finding appears
    expect($count(antiCheatConfig([$key => $value])))->toBeGreaterThan(0);
})->with([
    ['burst slow', 'anticheat.burst.window_minutes', 30],
    ['user burst slow', 'anticheat.user_burst.minutes', 10],
]);
