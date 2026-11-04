<?php

namespace App\Actions\Notification;

use App\Enums\NotificationType;

/** Everything `RecordNotification` needs to write one bell row (and, from Phase B, one outbox row). No logic. */
final class NotificationDraft
{
    /**
     * @param  string  $dedupeKey  `review:{review_id}`, `launch_reminder:{product_id}:{launch_date}`, `product_live:{product_id}:{launch_date}` or `comment:{comment_id}`
     * @param  array<string, mixed>  $data  message_key, product_id, review_id|comment_id, params, href
     * @param  string|null  $emailKey  idempotency key of the outbox row; null means no mail
     * @param  array<string, mixed>  $emailPayload  ids and small scalars only
     */
    public function __construct(
        public readonly string $userId,
        public readonly NotificationType $type,
        public readonly string $dedupeKey,
        public readonly array $data,
        public readonly ?string $emailKey = null,
        public readonly array $emailPayload = [],
    ) {}
}
