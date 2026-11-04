<?php

use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Rules\SharedDeviceRule;
use App\Actions\AntiCheat\Scan;
use App\Console\Commands\DetectAbuseCommand;
use App\Enums\RiskSignalKind;
use App\Models\RiskSignal;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Exceptions;

it('prints one line per kind and exits 0 on a clean run', function () {
    // Given a ring and a throwaway address
    antiCheatFreeze();
    antiCheatCases();

    // When the command runs
    // Then it prints the counts in kind order and succeeds
    $this->artisan('launchpad:detect-abuse')
        ->expectsOutput('disposable_email: 1')->expectsOutput('fresh_account: 1')->expectsOutput('shared_device: 4')->expectsOutput('vote_burst: 1')
        ->expectsOutput('users rescored: 7')->expectsOutput('votes flagged: 0, unflagged: 0')
        ->assertExitCode(0);
    expect(RiskSignal::query()->count())->toBe(7);
});

it('prints how many members were rescored and how many votes were flagged and unflagged', function () {
    // Given a member whose signals add up to the flag score (stored score stale) with a counted vote, and a member without signals with a flagged vote
    antiCheatFreeze();
    $high = antiCheatSetScore(User::factory()->create(['created_at' => antiCheatNow()->subDays(30)]), 10); // a stale stored score puts the member in the rescored set
    antiCheatSignal($high, RiskSignalKind::SharedDevice, 40, 0);
    antiCheatSignal($high, RiskSignalKind::CoordinatedVotes, 35, 0);
    $highVote = antiCheatVote($high, antiCheatLiveProduct());
    $calm = User::factory()->create(['created_at' => antiCheatNow()->subDays(30)]);
    $calmVote = antiCheatVote($calm, antiCheatLiveProduct(), ['status' => 'flagged']);

    // When the command runs
    // Then both lines say what the run did: one member was rescored (75), one vote flagged and one unflagged
    $this->artisan('launchpad:detect-abuse')->expectsOutput('users rescored: 1')->expectsOutput('votes flagged: 1, unflagged: 1')->assertExitCode(0);
    expect(antiCheatVoteStatus($highVote))->toBe('flagged')->and(antiCheatVoteStatus($calmVote))->toBe('counted')->and(antiCheatScoreOf($high))->toBe(75);
});

it('describes the scoring and flagging in its description', function () {
    // Given the command
    // When its description is read
    // Then it names the rescoring and the flagging, not only the detection
    $description = (new DetectAbuseCommand)->getDescription();
    expect($description)->toContain('rescore')->toContain('flag');
});

it('says so when nothing new was found', function () {
    // Given an empty system
    // When the command runs
    // Then it reports no new signals and exits 0
    $this->artisan('launchpad:detect-abuse')->expectsOutput('No new signals.')->assertExitCode(0);
});

it('exits 1 and prints the failed count when a chunk throws, saving what it could', function () {
    // Given two rings and a rule that fails for one product
    antiCheatFreeze();
    $first = antiCheatLiveProduct();
    $second = antiCheatLiveProduct();
    antiCheatRing($first, 3, 'a', userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    antiCheatRing($second, 3, 'b', userAttributes: ['created_at' => antiCheatNow()->subDays(10)]);
    $bad = min($first->id, $second->id);
    app()->bind(SharedDeviceRule::class, fn () => new class($bad) extends SharedDeviceRule
    {
        public function __construct(private readonly string $bad) {}

        public function detect(RuleConfig $config, Scan $scan): iterable
        {
            if (in_array($this->bad, $scan->productIds, true)) {
                throw new RuntimeException('boom');
            }

            yield from parent::detect($config, $scan);
        }
    });
    Exceptions::fake();

    // When the command runs (both products share the one default chunk, so the whole chunk fails)
    // Then it exits 1 with the count
    $this->artisan('launchpad:detect-abuse')->expectsOutput('Failed chunks: 1')->assertExitCode(1);
});

it('prints a dry run notice and saves nothing', function () {
    // Given a ring
    antiCheatFreeze();
    antiCheatCases();

    // When the command runs with --dry-run
    // Then the counts are printed, the notice is shown, and no row remains
    $this->artisan('launchpad:detect-abuse', ['--dry-run' => true])->expectsOutput('Dry run: nothing was saved.')->expectsOutput('shared_device: 4')->assertExitCode(0);
    expect(RiskSignal::query()->count())->toBe(0);
});

it('is scheduled every five minutes behind a mutex that skips an overlapping run', function () {
    // Given the scheduled event of the command
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'launchpad:detect-abuse'));

    // When its mutex is taken, then released
    $event->mutex->create($event);
    $blocked = $event->filtersPass(app());
    $event->mutex->forget($event);
    $free = $event->filtersPass(app());

    // Then a held mutex stops the run and a released one lets it start
    expect($event->expression)->toBe('*/5 * * * *')->and($event->expiresAt)->toBe(10)->and($event->onOneServer)->toBeTrue()->and($event->runInBackground)->toBeTrue();
    expect($blocked)->toBeFalse()->and($free)->toBeTrue();
    expect($event->description)->toBeNull()->and(app(Schedule::class)->events())->toContain($event);
});
