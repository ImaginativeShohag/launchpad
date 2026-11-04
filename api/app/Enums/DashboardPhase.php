<?php

namespace App\Enums;

use App\Models\Product;
use Carbon\CarbonImmutable;

/** Where a product stands relative to its launch day (UTC); the client picks its empty-state copy from it. */
enum DashboardPhase: string
{
    case BeforeLaunch = 'before_launch';
    case LaunchDay = 'launch_day';
    case After = 'after';

    /**
     * before_launch: not live (or taken down), no launch date, or the launch day is still ahead. launch_day: the launch date is
     * today. after: it is past. A taken-down product keeps the phase of its launch date (its history stays visible to its maker).
     */
    public static function of(Product $product, CarbonImmutable $now): self
    {
        $status = $product->status;
        $launch = $product->launch_date;

        if (($status !== ProductStatus::Live && $status !== ProductStatus::TakenDown) || $launch === null) {
            return self::BeforeLaunch;
        }

        $today = $now->utc()->toDateString();
        $day = $launch->toDateString();

        return match (true) {
            $day > $today => self::BeforeLaunch,
            $day === $today => self::LaunchDay,
            default => self::After,
        };
    }
}
