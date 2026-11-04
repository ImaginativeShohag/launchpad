<?php

namespace App\Support;

use App\Enums\SettingKey;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class Settings
{
    private const CACHE_MINUTES = 10;

    public function get(SettingKey $key): int|string
    {
        $cacheKey = $this->cacheKey($key);
        $cached = Cache::get($cacheKey);
        // The Redis cache store hands an integer back as a numeric string; only correctly typed values are ever cached, so a
        // digit string under an integer key is that round trip. Without this every integer setting read from a warm Redis cache
        // would look wrongly typed and silently serve its default.
        if (is_string($cached) && is_int($key->default()) && preg_match('/\A-?\d{1,10}\z/', $cached) === 1) {
            $cached = (int) $cached;
        }
        if (is_int($cached) || is_string($cached)) {
            return $cached;
        }

        $row = Setting::query()->find($key->value);
        $value = $row === null ? $key->default() : ($row->value ?? $key->default());

        if (get_debug_type($value) !== get_debug_type($key->default())) {
            // Never cache or log the bad value; serve the default until it is fixed.
            Log::warning('Setting has a wrongly typed stored value; using the default.', ['key' => $key->value]);

            return $key->default();
        }

        /** @var int|string $value */
        // A cache write must never break a read (it is a write, and a read-only snapshot transaction refuses it with 25006).
        try {
            Cache::put($cacheKey, $value, now()->addMinutes(self::CACHE_MINUTES));
        } catch (Throwable $e) {
            report($e);
        }

        return $value;
    }

    /**
     * A decimal setting (stored as a string, validated on write). A stored value that is not a plain decimal, or is
     * outside the key's range (a direct database edit, or rules tightened later), serves the default instead of
     * reaching the ranking maths.
     */
    public function decimal(SettingKey $key): float
    {
        $value = $this->get($key);

        if (is_string($value) && preg_match(SettingKey::DECIMAL_PATTERN, $value) === 1) {
            [$min, $max] = $key->decimalRange();

            if ((float) $value >= $min && (float) $value <= $max) {
                return (float) $value;
            }
        }

        return (float) $key->default();
    }

    /**
     * A retention window in whole days. A stored value that is not an integer or is outside the key's range (a direct
     * database edit, `0`, a negative number, rules tightened later) serves the default: a corrupt value must never
     * shorten a window to "purge everything".
     */
    public function days(SettingKey $key): int
    {
        $value = $this->get($key);
        [$min, $max] = $key->dayRange();

        if (is_int($value) && $value >= $min && $value <= $max) {
            return $value;
        }

        // Key only, never the stored value. A wrong type was already logged by get(); this covers an int out of range.
        Log::warning('Retention setting is invalid; using the default.', ['key' => $key->value]);

        return (int) $key->default();
    }

    /**
     * A plain integer setting. A stored value that is not an integer or is outside the key's range (a direct database
     * edit, rules tightened later) serves the default, and the warning names the key, never the value.
     */
    public function integer(SettingKey $key): int
    {
        $value = $this->get($key);
        [$min, $max] = $key->intRange();

        if (is_int($value) && $value >= $min && $value <= $max) {
            return $value;
        }

        Log::warning('Integer setting is invalid; using the default.', ['key' => $key->value]);

        return (int) $key->default();
    }

    /**
     * A digest send time as `H:i` (UTC). A stored value that is not exactly `HH:MM` before 22:00 (a later send time leaves no room before the digest give-up hour) (a direct database
     * edit, rules tightened later) serves the default, and the warning names the key, never the value.
     */
    public function timeOfDay(SettingKey $key): string
    {
        if ($key !== SettingKey::DigestDailySendAtUtc && $key !== SettingKey::DigestWeeklySendAtUtc) {
            throw new \LogicException($key->value.' is not a time-of-day setting.');
        }

        $value = $this->get($key);

        if (is_string($value) && preg_match('/\A([01]\d|2[01]):[0-5]\d\z/', $value) === 1) {
            return $value;
        }

        Log::warning('Digest time setting is invalid; using the default.', ['key' => $key->value]);

        return (string) $key->default();
    }

    /** One entry of a domain list: lowercase labels, a letter or punycode top-level label, no wildcard, no trailing dot. */
    public const DOMAIN_PATTERN = '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:[a-z]{2,24}|xn--[a-z0-9-]{2,20})\z/';

    /**
     * The disposable-domain list. A stored list that fails the write check (a direct database edit, rules tightened later)
     * serves the default list, and the warning names the key, never the value. A stored empty string is a valid empty list
     * (the rule is then inert); the admin endpoint itself never accepts an empty write.
     *
     * @return list<string>
     */
    public function domains(SettingKey $key = SettingKey::AnticheatDisposableDomains): array
    {
        if ($key !== SettingKey::AnticheatDisposableDomains) {
            throw new \LogicException($key->value.' is not a domain list setting.');
        }

        $value = $this->get($key);
        $parsed = $value === '' ? [] : self::parseDomains((string) $value);

        if ($parsed !== null) {
            return $parsed;
        }

        Log::warning('Domain list setting is invalid; using the default.', ['key' => $key->value]);

        return self::parseDomains((string) $key->default()) ?? [];
    }

    /**
     * The one parser behind the write check and the read path: comma separated, entries trimmed and deduplicated.
     * Null means invalid (an over-long list, too many entries, an empty entry, or an entry that is not a plain lowercase domain).
     *
     * @return list<string>|null
     */
    public static function parseDomains(string $csv): ?array
    {
        if (strlen($csv) > SettingKey::DOMAINS_MAX_CHARS) {
            return null;
        }

        $entries = array_map('trim', explode(',', $csv));

        if (count($entries) > SettingKey::DOMAINS_MAX_ENTRIES) {
            return null;
        }

        foreach ($entries as $entry) {
            if (preg_match(self::DOMAIN_PATTERN, $entry) !== 1) {
                return null;
            }
        }

        return array_values(array_unique($entries));
    }

    /**
     * The product integrity bands: `watch` and `alert` ratios. The alert ratio must stay strictly below the watch ratio; a
     * stored pair that is inverted (a direct database edit) serves both defaults, with a warning that names the keys.
     *
     * @return array{watch: float, alert: float}
     */
    public function integrityBands(): array
    {
        $watch = $this->decimal(SettingKey::AnticheatProductWatchRatio);
        $alert = $this->decimal(SettingKey::AnticheatProductAlertRatio);

        if ($alert < $watch) {
            return ['watch' => $watch, 'alert' => $alert];
        }

        Log::warning('Integrity band settings are inverted; using the defaults.', [
            'keys' => [SettingKey::AnticheatProductWatchRatio->value, SettingKey::AnticheatProductAlertRatio->value],
        ]);

        return [
            'watch' => (float) SettingKey::AnticheatProductWatchRatio->default(),
            'alert' => (float) SettingKey::AnticheatProductAlertRatio->default(),
        ];
    }

    /** Unchanged values are skipped entirely: no write, no audit row. */
    public function set(SettingKey $key, int|string $value, User $actor): void
    {
        if (get_debug_type($value) !== get_debug_type($key->default())) {
            throw new InvalidArgumentException('Setting '.$key->value.' expects a value of type '.get_debug_type($key->default()).'.');
        }

        DB::transaction(function () use ($key, $value, $actor) {
            $row = Setting::query()->lockForUpdate()->find($key->value);
            $before = $row === null ? $key->default() : ($row->value ?? $key->default());

            if ($before === $value) {
                return;
            }

            Setting::query()->updateOrCreate(
                ['key' => $key->value],
                ['value' => $value, 'updated_by' => $actor->getKey()],
            );

            Audit::record('setting.updated', $actor, null, [
                'key' => $key->value,
                'before' => $before,
                'after' => $value,
            ]);

            // Only once the outermost transaction has committed, so a reader cannot re-cache the old value.
            DB::afterCommit(function () use ($key): void {
                // The change is already committed: a cache outage must not turn it into an error. The
                // entry expires on its own (CACHE_MINUTES), so a missed forget is bounded.
                try {
                    Cache::forget($this->cacheKey($key));
                } catch (Throwable $e) {
                    report($e);
                }
            });
        });
    }

    /** @return array<string, array{value: int|string, default: int|string}> */
    public function all(): array
    {
        $all = [];
        foreach (SettingKey::cases() as $key) {
            $all[$key->value] = ['value' => $this->get($key), 'default' => $key->default()];
        }

        return $all;
    }

    private function cacheKey(SettingKey $key): string
    {
        return 'settings:'.$key->value;
    }
}
