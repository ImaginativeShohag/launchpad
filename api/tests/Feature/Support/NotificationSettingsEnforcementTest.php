<?php

use App\Actions\Email\QueueDigest;
use App\Actions\Email\QueueLaunchReminders;
use App\Actions\Retention\PurgeAuditNetwork;
use App\Actions\Retention\PurgeAuthEvents;
use App\Actions\Retention\PurgeDigestRuns;
use App\Actions\Retention\PurgeEmailMessages;
use App\Actions\Retention\PurgeExports;
use App\Actions\Retention\PurgeNotifications;
use App\Actions\Retention\PurgeRankSnapshots;
use App\Actions\Retention\PurgeRiskSignals;
use App\Actions\Retention\PurgeVoteHashes;
use App\Enums\RiskOutcome;
use App\Enums\Role;
use App\Enums\SettingKey;
use App\Jobs\GenerateExport;
use App\Models\Product;
use App\Models\User;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RetentionFixtures.php';
require_once __DIR__.'/../../Support/DigestFixtures.php';

/** @return list<SettingKey> the keys this audit covers: every digest time and every retention window */
function enforcedSettingKeys(): array
{
    return array_values(array_filter(SettingKey::cases(), fn (SettingKey $key) => str_starts_with($key->value, 'retention.') || str_starts_with($key->value, 'digest.')));
}

/**
 * Setting key => the classes whose source must read it. `null` would mark a key nothing enforces yet; there is none now.
 *
 * @return array<string, list<class-string>|null>
 */
function settingsEnforcedBy(): array
{
    return [
        'digest.daily_send_at_utc' => [QueueDigest::class, QueueLaunchReminders::class],
        'digest.weekly_send_at_utc' => [QueueDigest::class],
        'retention.auth_events_days' => [PurgeAuthEvents::class],
        'retention.vote_hashes_days' => [PurgeVoteHashes::class],
        // Both risk signal windows (unreviewed 365 days, reviewed 90 days) are enforced by the one purge since anti-cheat phase C.
        'retention.risk_signals_days' => [PurgeRiskSignals::class],
        'retention.risk_signals_reviewed_days' => [PurgeRiskSignals::class],
        'retention.rank_snapshots_days' => [PurgeRankSnapshots::class],
        'retention.audit_network_days' => [PurgeAuditNetwork::class],
        'retention.notifications_days' => [PurgeNotifications::class],
        'retention.email_messages_days' => [PurgeEmailMessages::class, PurgeDigestRuns::class],
        // The job stamps each export's expires_at from the window; PurgeExports honours the stamp (it reads the row, not the setting).
        'retention.exports_days' => [GenerateExport::class],
    ];
}

it('pins an enforcing class for every digest time and retention window, with no exception left', function () {
    // Given the digest and retention keys of the enum and the audit map
    $map = settingsEnforcedBy();

    // When they are compared
    // Then every key has an entry (a new key must come with its enforcer), none is orphaned, and none is unenforced
    expect(array_keys($map))->toEqualCanonicalizing(array_map(fn (SettingKey $key) => $key->value, enforcedSettingKeys()));
    expect(array_keys(array_filter($map, fn ($classes) => $classes === null)))->toBe([]);
});

it('has an enforcing class whose source reads the key, for every enforced setting', function (string $key, array $classes) {
    // Given the case behind the key
    $case = SettingKey::from($key);
    $name = (new ReflectionEnum($case))->getCase($case->name)->getName();

    // When each enforcing class is read
    // Then it exists and its source reads exactly that key (through Settings)
    foreach ($classes as $class) {
        expect(class_exists($class))->toBeTrue($class);
        $source = file_get_contents((new ReflectionClass($class))->getFileName());
        expect($source)->toContain('SettingKey::'.$name);
    }
})->with(function () {
    $cases = [];
    foreach (settingsEnforcedBy() as $key => $classes) {
        if ($classes !== null) {
            $cases[$key] = [$key, $classes];
        }
    }

    return $cases;
});

it('moves the purge window when each retention setting changes', function (SettingKey $key, int $short, int $ageDays, Closure $seed, string $class) {
    // Given a row older than the new, shorter window but younger than the default one
    $this->travelTo(new DateTimeImmutable(RETENTION_NOW.' UTC'));
    $purged = ($seed($ageDays))();
    $admin = User::factory()->role(Role::Admin)->create();
    expect($key->default())->toBeGreaterThan($ageDays)->and($short)->toBeLessThan($ageDays);

    // When the purge runs with the default window
    app($class)->purge(retentionNow());
    $before = $purged();

    // And an admin shortens the window and the purge runs again
    app(Settings::class)->set($key, $short, $admin);
    app($class)->purge(retentionNow());
    $after = $purged();

    // Then the row survived the default and went with the shorter window
    expect($before)->toBeFalse()->and($after)->toBeTrue();
})->with([
    'auth events' => [SettingKey::RetentionAuthEventsDays, 30, 100, fn (int $age) => function () use ($age) {
        $id = retentionAuthEvent(retentionNow()->subDays($age));

        return fn () => ! DB::table('auth_events')->where('id', $id)->exists();
    }, PurgeAuthEvents::class],
    'vote hashes' => [SettingKey::RetentionVoteHashesDays, 30, 60, fn (int $age) => function () use ($age) {
        $vote = retentionVote(retentionNow()->subDays($age)->toDateString());

        return fn () => DB::table('votes')->where('id', $vote->id)->whereNull('ip_hash')->whereNull('device_hash')->whereNull('user_agent')->exists();
    }, PurgeVoteHashes::class],
    'rank snapshots' => [SettingKey::RetentionRankSnapshotsDays, 10, 20, fn (int $age) => function () use ($age) {
        $product = Product::factory()->live()->create();
        retentionSnapshot($product, retentionNow()->subDays($age));

        return fn () => DB::table('rank_snapshots')->where('product_id', $product->id)->doesntExist();
    }, PurgeRankSnapshots::class],
    'audit network' => [SettingKey::RetentionAuditNetworkDays, 60, 200, fn (int $age) => function () use ($age) {
        $id = retentionAuditLog(retentionNow()->subDays($age));

        return fn () => DB::table('audit_logs')->where('id', $id)->whereNull('ip_hash')->whereNull('user_agent')->exists();
    }, PurgeAuditNetwork::class],
    'notifications' => [SettingKey::RetentionNotificationsDays, 30, 60, fn (int $age) => function () use ($age) {
        $id = retentionBell(User::factory()->create()->id, retentionNow()->subDays($age));

        return fn () => DB::table('notifications')->where('id', $id)->doesntExist();
    }, PurgeNotifications::class],
    'email messages' => [SettingKey::RetentionEmailMessagesDays, 30, 60, fn (int $age) => function () use ($age) {
        $id = retentionMail(User::factory()->create()->id, 'sent', retentionNow()->subDays($age));

        return fn () => DB::table('email_messages')->where('id', $id)->doesntExist();
    }, PurgeEmailMessages::class],
    'digest runs (same key)' => [SettingKey::RetentionEmailMessagesDays, 30, 60, fn (int $age) => function () use ($age) {
        $period = retentionNow()->subDays($age)->toDateString();
        retentionDigestRun($period);

        return fn () => DB::table('digest_runs')->where('period_date', $period)->doesntExist();
    }, PurgeDigestRuns::class],
    'risk signals (unreviewed)' => [SettingKey::RetentionRiskSignalsDays, 100, 200, fn (int $age) => function () use ($age) {
        $id = antiCheatPurgeSignal($age);

        return fn () => DB::table('risk_signals')->where('id', $id)->doesntExist();
    }, PurgeRiskSignals::class],
    'risk signals (reviewed)' => [SettingKey::RetentionRiskSignalsReviewedDays, 30, 60, fn (int $age) => function () use ($age) {
        $id = antiCheatPurgeSignal(80, RiskOutcome::Confirmed, $age);

        return fn () => DB::table('risk_signals')->where('id', $id)->doesntExist();
    }, PurgeRiskSignals::class],
]);

it('moves the send window when a digest time setting changes', function (string $which) {
    // Given a Monday (both digests can be due), a frozen day, a launch tomorrow and a member
    $this->travelTo(new DateTimeImmutable('2026-10-26 08:00:00 UTC'));
    $admin = User::factory()->role(Role::Admin)->create();
    $launch = digestProduct('2026-10-25', ['votes_count' => 3]);
    digestFreeze('2026-10-25', [$launch]);
    Product::factory()->scheduled('2026-10-27')->create();
    $at = fn (string $time) => CarbonImmutable::parse('2026-10-26 '.$time, 'UTC');
    $due = function (string $time) use ($which, $at): bool {
        if ($which === 'launch reminders') {
            $reminders = app(QueueLaunchReminders::class);
            $reminders->handle($at($time));

            return ! $reminders->outsideWindow;
        }
        $results = digestAction()->handle($at($time));

        return ($which === 'daily digest' ? $results['daily_top5'] : $results['weekly_best']) !== null;
    };
    $key = $which === 'weekly digest' ? SettingKey::DigestWeeklySendAtUtc : SettingKey::DigestDailySendAtUtc;

    // When the time is moved from 07:00 to 09:30
    $beforeChange = [$due('08:00:00'), $due('09:30:00')];
    app(Settings::class)->set($key, '09:30', $admin);
    $afterChange = [$due('08:00:00'), $due('09:29:59'), $due('09:30:00')];

    // Then the new time decides, to the second
    expect($beforeChange)->toBe([true, true])->and($afterChange)->toBe([false, false, true]);
})->with(['daily digest', 'weekly digest', 'launch reminders']);
