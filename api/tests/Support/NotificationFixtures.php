<?php

use App\Actions\Notification\NotificationDraft;
use App\Enums\NotificationType;
use App\Models\User;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** A complete, valid draft for $user. */
function notifDraft(User|string $user, string $key = 'review:1', NotificationType $type = NotificationType::ReviewApproved, array $data = []): NotificationDraft
{
    return new NotificationDraft(
        $user instanceof User ? $user->id : $user,
        $type,
        $key,
        [...['message_key' => $type->messageKey(), 'product_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'review_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAW', 'params' => ['product_name' => 'Acme'], 'href' => '/me/products/01ARZ3NDEKTSV4RRFFQ69G5FAV'], ...$data],
    );
}

/** @return list<array{path: string, body: array<string, mixed>}> the Pusher HTTP calls the stubbed Reverb client was asked to make, decoded */
function notifFrames(): array
{
    return array_map(
        fn ($request) => ['path' => $request->getUri()->getPath(), 'body' => json_decode((string) $request->getBody(), true)],
        array_values(app('test.reverb.requests')->getArrayCopy()),
    );
}

/** The decoded frames sent on one channel, name and payload. @return list<array{name: string, data: array<string, mixed>, size: int}> */
function notifFramesOn(string $channel): array
{
    $found = [];
    foreach (array_values(app('test.reverb.requests')->getArrayCopy()) as $request) {
        $raw = (string) $request->getBody();
        $body = json_decode($raw, true);
        if (is_array($body) && ($body['channel'] ?? null) === $channel) {
            $found[] = ['name' => $body['name'], 'data' => json_decode($body['data'], true), 'size' => strlen($raw)];
        }
    }

    return $found;
}

function notifClearFrames(): void
{
    app('test.reverb.requests')->exchangeArray([]);
}

/** Makes every broadcast fail with a RuntimeException('Reverb is down'). */
function notifBreakBroadcaster(): void
{
    Broadcast::extend('failing', fn () => new class implements Broadcaster
    {
        public function auth($request) {}

        public function validAuthenticationResponse($request, $result) {}

        public function broadcast(array $channels, $event, array $payload = [])
        {
            throw new RuntimeException('Reverb is down');
        }
    });
    config(['broadcasting.default' => 'failing', 'broadcasting.connections.failing' => ['driver' => 'failing']]);
}

/** Raw bulk insert of $count notifications for $userId, newest last (microseconds apart). */
function notifBulk(string $userId, int $count, ?string $readAt = null, int $offset = 0): void
{
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
        $at = Carbon::parse('2026-10-20 10:00:00', 'UTC')->addMicroseconds(($offset + $i) * 1000)->format('Y-m-d H:i:s.uP');
        $rows[] = [
            'id' => (string) Str::uuid7(), 'user_id' => $userId, 'type' => 'review_approved', 'data' => '{"message_key":"notifications.review_approved"}',
            'dedupe_key' => 'bulk:'.Str::ulid(), 'read_at' => $readAt === null ? null : $at, 'created_at' => $at, 'updated_at' => $at,
        ];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::table('notifications')->insert($chunk);
    }
}
