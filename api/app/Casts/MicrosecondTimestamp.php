<?php

namespace App\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A timestamp that keeps its microseconds. Eloquent hands a Carbon to the driver formatted as `Y-m-d H:i:s`, which
 * silently truncates it to whole seconds; this cast writes the string itself. Used where the value is a token
 * that must tell two moments in the same second apart (`products.submitted_at`).
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string|null>
 */
class MicrosecondTimestamp implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if (! is_string($value)) {
            return null;
        }

        // The per-instance string format matters: cursor pagination turns the sort value into a string with
        // (string), whose default 'Y-m-d H:i:s' rounds the fraction away and makes the next page repeat rows.
        return Carbon::parse($value)->setTimezone((string) config('app.timezone'))
            ->settings(['toStringFormat' => 'Y-m-d H:i:s.uP']);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse((string) $value);

        return $moment->utc()->format('Y-m-d H:i:s.uP');
    }
}
