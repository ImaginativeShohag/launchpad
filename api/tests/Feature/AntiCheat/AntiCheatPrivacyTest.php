<?php

use App\Actions\Retention\PurgeVoteHashes;
use App\Enums\Role;
use App\Models\RiskSignal;
use App\Models\User;
use App\Models\Vote;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

beforeEach(fn () => antiCheatFreeze());

/** A world with hashes, IPs, e-mails and user agents: a ring, a script, a fresh account and a throwaway address, all with identifying data. */
function antiCheatPrivacyWorld(): array
{
    $case = antiCheatCases();
    DB::table('votes')->update(['user_agent' => 'Mozilla/5.0 PrivacyUA 10.0.0.1']);
    antiCheatAuthEvent($case['ring'][0], antiCheatNow()->subHour(), 'ring');
    antiCheatAuthEvent($case['ring'][1], antiCheatNow()->subHour(), 'ring');

    return $case;
}

it('leaks no hash, address, e-mail or user agent through signals, audit rows, logs or the responses of the review, void and suspend', function () {
    // Given the world, a log recorder, and an admin
    $handler = new TestHandler(Level::Debug);
    Log::driver()->getLogger()->pushHandler($handler);
    $case = antiCheatPrivacyWorld();
    $admin = signedInStaff(Role::Admin);

    // When detection and scoring run, one signal is reviewed, one member's votes are voided, one is suspended with the clean-up, and the evidence is read
    $result = antiCheatDetect();
    $signal = RiskSignal::query()->where('kind', 'shared_device')->orderBy('id')->firstOrFail();
    $throwawayVote = Vote::query()->where('user_id', $case['throwaway']->id)->firstOrFail();
    $responses = [
        $this->postJson("/api/v1/staff/risk-signals/{$signal->id}/review", ['outcome' => 'confirmed', 'note' => 'Same laptop'])->assertOk()->getContent(),
        $this->postJson("/api/v1/staff/risk/members/{$case['ring'][1]->id}/void-votes", ['reason' => 'Vote manipulation (ring)'])->assertOk()->getContent(),
        $this->postJson("/api/v1/staff/risk/members/{$case['ring'][2]->id}/suspend", ['reason' => 'Ring of fake accounts'])->assertOk()->getContent(),
        $this->postJson("/api/v1/staff/votes/{$throwawayVote->id}/void", ['reason' => 'Throwaway address'])->assertOk()->getContent(),
        $this->getJson("/api/v1/staff/risk-signals/{$signal->id}")->assertOk()->getContent(),
        $this->getJson("/api/v1/staff/risk/members/{$case['ring'][2]->id}")->assertOk()->getContent(),
        $this->getJson("/api/v1/staff/products/{$case['product']->id}/vote-integrity")->assertOk()->getContent(),
        $this->getJson("/api/v1/staff/products/{$case['product']->id}/votes?per_page=100")->assertOk()->getContent(),
    ];

    // Then no response carries a hash, an address, an e-mail or a user agent
    foreach ($responses as $i => $body) {
        expect(antiCheatPrivacyLeaks($body))->toBe([], "response {$i}");
    }

    // And neither does any stored evidence (details) or any audit meta written along the way
    expect($result->signals)->not->toBe([]);
    foreach (DB::table('risk_signals')->pluck('details') as $details) {
        expect(antiCheatPrivacyLeaks((string) $details))->toBe([], 'risk_signals.details');
    }
    expect(DB::table('audit_logs')->where('actor_id', $admin->id)->count())->toBeGreaterThan(3);
    foreach (DB::table('audit_logs')->pluck('meta') as $meta) {
        expect(antiCheatPrivacyLeaks((string) $meta))->toBe([], 'audit_logs.meta');
    }

    // And nothing in the log does
    foreach ($handler->getRecords() as $record) {
        expect(antiCheatPrivacyLeaks($record->message.json_encode($record->context)))->toBe([], 'log');
    }
    Log::driver()->getLogger()->popHandler();
});

it('shows only 8 hex fingerprints while hashes exist and null once the retention purge emptied them, with the evidence ids still resolving', function () {
    // Given a detected ring and a reviewer
    $case = antiCheatPrivacyWorld();
    antiCheatDetect();
    $signal = RiskSignal::query()->where('kind', 'shared_device')->orderBy('id')->firstOrFail();
    signedInStaff(Role::Reviewer);

    // When the votes are read before the purge
    $before = $this->getJson("/api/v1/staff/products/{$case['product']->id}/votes?per_page=100")->assertOk()->json('data');

    // Then every fingerprint is 8 hex characters
    expect($before)->not->toBeEmpty();
    foreach ($before as $vote) {
        expect($vote['device_fp'])->toMatch('/^[0-9a-f]{8}$/')->and($vote['ip_fp'])->toMatch('/^[0-9a-f]{8}$/');
    }

    // And once the purge emptied the hashes of old votes, the fingerprints are null while the evidence ids still resolve
    DB::table('products')->where('id', $case['product']->id)->update(['launch_date' => antiCheatNow()->subDays(120)->toDateString()]);
    app(PurgeVoteHashes::class)->purge(antiCheatNow());
    $detail = $this->getJson("/api/v1/staff/risk-signals/{$signal->id}")->assertOk()->json();
    $after = $this->getJson("/api/v1/staff/products/{$case['product']->id}/votes?per_page=100")->assertOk()->json('data');
    expect(array_column($after, 'id'))->toEqualCanonicalizing(array_column($before, 'id'));
    foreach ($after as $vote) {
        expect($vote['device_fp'])->toBeNull()->and($vote['ip_fp'])->toBeNull();
    }
    expect(json_encode($detail))->toContain($signal->details['user_ids'][0]);
});
