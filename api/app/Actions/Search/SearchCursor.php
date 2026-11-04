<?php

namespace App\Actions\Search;

use App\Enums\SearchSort;

/**
 * The keyset cursor of a search page. The same base64url JSON shape Laravel's cursor uses (ordered keys plus
 * `_pointsToNextItems`), so the one KeysetCursor rule validates it; the leading `h` binds it to the search it came from.
 */
final class SearchCursor
{
    /** @return array<string, 'hash'|'decimal'|'int'|'ulid'|'day'|'date'> the KeysetCursor shape for the sort, `h` first */
    public static function shape(SearchSort $sort): array
    {
        return match ($sort) {
            SearchSort::Relevance => ['h' => 'hash', 'score' => 'decimal', 'votes_count' => 'int', 'id' => 'ulid'],
            SearchSort::Votes => ['h' => 'hash', 'votes_count' => 'int', 'launch_date' => 'day', 'id' => 'ulid'],
            SearchSort::Newest => ['h' => 'hash', 'launched_at' => 'date', 'id' => 'ulid'],
        };
    }

    /** @param  array<string, string|int>  $values  keys exactly shape($sort) minus `h` */
    public static function encode(SearchSort $sort, string $hash, array $values): string
    {
        $payload = ['h' => $hash];

        foreach (array_keys(self::shape($sort)) as $key) {
            if ($key !== 'h') {
                $payload[$key] = $values[$key];
            }
        }

        $payload['_pointsToNextItems'] = true;

        return rtrim(strtr(base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array<string, string|int>|null the cursor values (with `h`, without the direction flag), null when it does not decode */
    public static function decode(string $cursor): ?array
    {
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $cursor) !== 1) {
            return null;
        }

        $base64 = strtr($cursor, '-_', '+/');
        $json = base64_decode(str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '='), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ! array_key_exists('_pointsToNextItems', $data)) {
            return null;
        }

        unset($data['_pointsToNextItems']);

        foreach ($data as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value))) {
                return null;
            }
        }

        /** @var array<string, string|int> $data */
        return $data;
    }
}
