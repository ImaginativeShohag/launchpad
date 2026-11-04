<?php

use App\Enums\AuthEventType;
use App\Models\AuthEvent;
use App\Models\User;
use App\Support\AuthLog;
use Illuminate\Http\Request;

it('stores hashed ip and device, and a truncated user agent', function () {
    // Given a user and a request with a device id and a long user agent
    $user = User::factory()->create();
    $request = Request::create('/', 'GET', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => str_repeat('b', 400),
    ]);
    $request->attributes->set('device_id', 'device-abcdefghijkl');
    $key = config('launchpad.hmac_key');

    // When an event is recorded
    $event = AuthLog::record(AuthEventType::cases()[0], $user->id, $request);

    // Then the stored row holds only digests and the agent is truncated
    $row = AuthEvent::query()->findOrFail($event->id);
    expect($row->user_id)->toBe($user->id);
    expect($row->event)->toBe(AuthEventType::cases()[0]);
    expect($row->ip_hash)->toBe(hash_hmac('sha256', '203.0.113.7', $key))->not->toContain('203.0.113.7');
    expect($row->device_hash)->toBe(hash_hmac('sha256', 'device-abcdefghijkl', $key));
    expect($row->user_agent)->toHaveLength(255);
    expect($row->created_at)->not->toBeNull();
});

it('stores a null user agent and null device hash when absent', function () {
    // Given a request without user agent or device id
    $request = Request::create('/');
    $request->headers->remove('User-Agent');

    // When an event is recorded
    $event = AuthLog::record(AuthEventType::cases()[0], null, $request);

    // Then those columns are null
    $row = AuthEvent::query()->findOrFail($event->id);
    expect($row->user_agent)->toBeNull();
    expect($row->device_hash)->toBeNull();
});
