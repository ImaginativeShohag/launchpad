<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\EmailStatus;
use App\Enums\EmailType;
use Database\Factories\EmailMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One row of the durable mail outbox. Written by `RecordNotification` (and the digest jobs), claimed and sent by the
 * `launchpad:send-emails` command. `payload` holds ids and small scalars only.
 *
 * @property EmailType $type
 * @property EmailStatus $status
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class EmailMessage extends Model
{
    /** @use HasFactory<EmailMessageFactory> */
    use HasFactory, HasUlids;

    /** Nothing is mass-assigned: writers use forceFill or the outbox actions. */
    protected $fillable = [];

    /** Off on purpose: Eloquent would truncate both timestamps to whole seconds; the writers set them with microseconds. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'type' => EmailType::class,
            'status' => EmailStatus::class,
            'payload' => 'array',
            'attempts' => 'integer',
            'next_attempt_at' => MicrosecondTimestamp::class,
            'sent_at' => MicrosecondTimestamp::class,
            'created_at' => MicrosecondTimestamp::class,
            'updated_at' => MicrosecondTimestamp::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
