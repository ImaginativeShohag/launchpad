<?php

use App\Actions\AntiCheat\RiskSignalKey;
use Carbon\CarbonImmutable;

const KEY_U = '01J9ZZZZZZZZZZZZZZZZZZZZZA';
const KEY_P = '01J9ZZZZZZZZZZZZZZZZZZZZZB';

it('builds each dedupe key exactly as documented', function () {
    // Given a user, a product (or maker) and an instant
    $now = CarbonImmutable::parse('2026-10-21 12:34:56', 'UTC');

    // When every builder runs
    // Then the keys are kind:subject:scope
    expect(RiskSignalKey::sharedDevice(KEY_U, KEY_P))->toBe('shared_device:'.KEY_U.':'.KEY_P);
    expect(RiskSignalKey::ipCluster(KEY_U, KEY_P))->toBe('ip_cluster:'.KEY_U.':'.KEY_P);
    expect(RiskSignalKey::burstUser(KEY_U, '2026-10-21'))->toBe('vote_burst:user:'.KEY_U.':2026-10-21');
    expect(RiskSignalKey::burstProduct(KEY_P, $now))->toBe('vote_burst:product:'.KEY_P.':2026102112');
    expect(RiskSignalKey::freshAccount(KEY_U))->toBe('fresh_account:'.KEY_U);
    expect(RiskSignalKey::disposableEmail(KEY_U))->toBe('disposable_email:'.KEY_U);
    expect(RiskSignalKey::singleMakerVoter(KEY_U, KEY_P))->toBe('single_maker_voter:'.KEY_U.':'.KEY_P);
    expect(RiskSignalKey::loginChurn(KEY_U, '2026-10-21'))->toBe('login_churn:'.KEY_U.':2026-10-21');
    expect(RiskSignalKey::coordinatedVotes(KEY_U, '2026-10-21'))->toBe('coordinated_votes:'.KEY_U.':2026-10-21');
});

it('keeps the longest key far under the varchar(160) column', function () {
    // Given the two-ulid keys
    // When the longest is built
    // Then it is 72 characters
    expect(strlen(RiskSignalKey::singleMakerVoter(KEY_U, KEY_P)))->toBe(72)->toBeLessThan(160);
    expect(strlen(RiskSignalKey::burstProduct(KEY_P, CarbonImmutable::now())))->toBeLessThan(160);
});

it('changes the product burst key at the hour edge and keeps it inside the hour, per product', function () {
    // Given instants one second either side of the hour
    $last = CarbonImmutable::parse('2026-10-21 11:59:59', 'UTC');
    $first = CarbonImmutable::parse('2026-10-21 12:00:00', 'UTC');
    $inside = CarbonImmutable::parse('2026-10-21 12:59:59', 'UTC');

    // When the keys are built
    // Then the hour decides and two products never share a key
    expect(RiskSignalKey::burstProduct(KEY_P, $last))->not->toBe(RiskSignalKey::burstProduct(KEY_P, $first));
    expect(RiskSignalKey::burstProduct(KEY_P, $first))->toBe(RiskSignalKey::burstProduct(KEY_P, $inside));
    expect(RiskSignalKey::burstProduct(KEY_P, $first))->not->toBe(RiskSignalKey::burstProduct(KEY_U, $first));
});

it('formats the product burst hour in UTC whatever zone the instant carries', function () {
    // Given the same instant in Dhaka (UTC+6), already on the next calendar day
    $dhaka = CarbonImmutable::parse('2026-10-22 02:30:00', 'Asia/Dhaka');

    // When the key is built
    // Then it names the UTC hour
    expect(RiskSignalKey::burstProduct(KEY_P, $dhaka))->toBe('vote_burst:product:'.KEY_P.':2026102120');
});
