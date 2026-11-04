<?php

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\Request;

it('stores a keyed ip hash, the subject and a truncated user agent', function () {
    // Given an actor, a subject and a request with a very long user agent
    $actor = User::factory()->create();
    $subject = User::factory()->create();
    $request = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => str_repeat('a', 400),
    ]);

    // When an audit entry is recorded
    $log = Audit::record('user.suspended', $actor, $subject, ['reason' => 'spam'], $request);

    // Then the stored row has the hash, subject and truncated agent, and is readable
    $log = $log->fresh();
    expect($log->ip_hash)->toBe(hash_hmac('sha256', '203.0.113.7', config('launchpad.hmac_key')))
        ->and($log->ip_hash)->toHaveLength(64)->not->toContain('203.0.113.7');
    expect($log->user_agent)->toHaveLength(255);
    expect($log->subject_type)->toBe($subject->getMorphClass());
    expect($log->subject_id)->toBe($subject->id);
    expect($log->actor_id)->toBe($actor->id);
    expect($log->meta)->toBe(['reason' => 'spam']);
    expect($log->created_at)->not->toBeNull();
});

it('stores a null user agent when the request has none', function () {
    // Given a request without a user agent
    $request = Request::create('/');
    $request->headers->remove('User-Agent');

    // When an audit entry is recorded
    $log = Audit::record('something.happened', null, null, [], $request)->fresh();

    // Then the user agent is null, not an empty string
    expect($log->user_agent)->toBeNull();
});
