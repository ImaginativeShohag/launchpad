<?php

namespace App\Actions\Dashboard;

use App\Enums\DashboardPhase;
use App\Models\Product;
use Carbon\CarbonImmutable;

/**
 * The UTC date window of a dashboard series or referrer read: the defaults, the limits (7 days of hours, 366 days of days),
 * and the clamp to [launch_date, today]. It is built once per request from the already-syntax-checked query values; the
 * request turns errors() into 422 messages and the actions read from/to/empty.
 *
 * Rules (the spec's section 6): defaults are from = launch_date and to = min(launch_date, today) for hours, to = today for
 * days and referrers. A side that is not given is derived so the window never exceeds the cap (a product older than 366 days
 * does not fail its own default read). Ordering and length are checked on what the caller wrote (before the clamp); the clamp
 * then moves `from` up to the launch date and `to` down to today, and a window that is empty afterwards is `empty`.
 * A product before its launch has no window at all (empty, from/to null).
 */
final class Window
{
    public const HOUR_DAYS = 7;

    public const DAY_DAYS = 366;

    /** @param array<string, string> $errors field => message */
    private function __construct(
        public readonly string $granularity,
        public readonly ?CarbonImmutable $from,
        public readonly ?CarbonImmutable $to,
        public readonly bool $empty,
        private readonly array $errors,
    ) {}

    public static function series(Product $product, ?string $granularity, ?string $from, ?string $to, CarbonImmutable $today): self
    {
        $granularity ??= 'hour';

        if ($granularity !== 'hour' && $granularity !== 'day') {
            return new self('hour', null, null, true, ['granularity' => 'The granularity must be hour or day.']);
        }

        return self::build($product, $granularity, $granularity === 'hour' ? self::HOUR_DAYS : self::DAY_DAYS, $from, $to, $today);
    }

    public static function range(Product $product, ?string $from, ?string $to, CarbonImmutable $today): self
    {
        return self::build($product, 'day', self::DAY_DAYS, $from, $to, $today);
    }

    /** @return array<string, string> field => message; empty when the window is valid */
    public function errors(): array
    {
        return $this->errors;
    }

    private static function build(Product $product, string $granularity, int $cap, ?string $from, ?string $to, CarbonImmutable $today): self
    {
        $today = $today->utc()->startOfDay();
        $explicitFrom = self::day($from);
        $explicitTo = self::day($to);
        $launch = $product->launch_date === null ? null : self::day($product->launch_date->toDateString());

        if ($explicitFrom !== null && $explicitTo !== null && $explicitFrom->greaterThan($explicitTo)) {
            return new self($granularity, null, null, true, ['from' => 'The from date must not be after the to date.']);
        }

        if ($launch === null) {
            // Nothing to default or clamp against; a window the caller wrote must still respect the cap.
            return new self($granularity, null, null, true, self::tooLong($explicitFrom, $explicitTo, $cap, $granularity));
        }

        if ($explicitFrom !== null && $explicitTo !== null) {
            // Both sides written: the caller's own span is checked before any clamp.
            $errors = self::tooLong($explicitFrom, $explicitTo, $cap, $granularity);
            if ($errors !== []) {
                return new self($granularity, null, null, true, $errors);
            }
            $start = $explicitFrom;
            $end = $explicitTo;
        } elseif ($explicitFrom !== null) {
            // Only `from`: it is clamped up to the launch first; an hourly window then runs one cap's worth of days
            // (a day window runs to today), and the cap is checked on what is left after the clamp.
            $start = self::later($explicitFrom, $launch);
            $end = $granularity === 'hour' ? $start->addDays($cap - 1) : $today;
            $errors = self::tooLong($start, $end->greaterThan($today) ? $today : $end, $cap, $granularity, 'from');
            if ($errors !== []) {
                return new self($granularity, null, null, true, $errors);
            }
        } else {
            $end = $explicitTo ?? ($granularity === 'hour' ? ($launch->lessThan($today) ? $launch : $today) : $today);
            $start = self::later($launch, $end->subDays($cap - 1));
        }

        if (DashboardPhase::of($product, $today) === DashboardPhase::BeforeLaunch) {
            return new self($granularity, null, null, true, []);
        }

        $start = self::later($start, $launch);
        $end = $end->greaterThan($today) ? $today : $end;

        return new self($granularity, $start, $end, $start->greaterThan($end), []);
    }

    /** @return array<string, string> */
    private static function tooLong(?CarbonImmutable $from, ?CarbonImmutable $to, int $cap, string $granularity, string $field = 'to'): array
    {
        if ($from === null || $to === null || (int) $from->diffInDays($to) + 1 <= $cap) {
            return [];
        }

        return [$field => $granularity === 'hour'
            ? 'An hourly window may span at most '.$cap.' days.'
            : 'A window may span at most '.$cap.' days.'];
    }

    private static function later(CarbonImmutable $a, CarbonImmutable $b): CarbonImmutable
    {
        return $a->greaterThan($b) ? $a : $b;
    }

    private static function day(?string $date): ?CarbonImmutable
    {
        if ($date === null) {
            return null;
        }

        return CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
    }
}
