<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use App\Enums\ReportReason;
use App\Enums\ReportStatus;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's report of a comment or a product. A report never hides anything by itself.
 *
 * @property ReportStatus $status
 * @property ReportReason $reason
 * @property string $reportable_type
 * @property string $reportable_id
 * @property string $reporter_id
 * @property string|null $details
 * @property string|null $handled_by
 * @property string|null $resolution_note
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $handled_at
 * @property User $reporter
 * @property User|null $handler
 */
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory, HasUlids;

    /** Nothing is mass-assigned: the report actions use forceFill. */
    protected $fillable = [];

    /** Off like on Comment: Eloquent would truncate both columns to whole seconds; the actions and the factory set them. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'reason' => ReportReason::class,
            'created_at' => MicrosecondTimestamp::class,
            'updated_at' => MicrosecondTimestamp::class,
            'handled_at' => MicrosecondTimestamp::class,
        ];
    }

    /** @param  Builder<Report>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('reports.status', ReportStatus::Open->value);
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<User, $this> */
    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }
}
