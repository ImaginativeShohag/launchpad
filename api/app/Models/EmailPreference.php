<?php

namespace App\Models;

use App\Casts\MicrosecondTimestamp;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A member's choice for one optional mail type; a missing row means "on". Writes go through the query builder
 * (`INSERT ... ON CONFLICT DO UPDATE`), the model only reads.
 *
 * @property bool $enabled
 * @property Carbon $updated_at
 */
class EmailPreference extends Model
{
    protected $fillable = [];

    public $incrementing = false;

    public $timestamps = false;

    protected $primaryKey = 'user_id';

    protected $keyType = 'string';

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'updated_at' => MicrosecondTimestamp::class];
    }
}
