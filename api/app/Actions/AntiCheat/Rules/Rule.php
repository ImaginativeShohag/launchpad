<?php

namespace App\Actions\AntiCheat\Rules;

use App\Actions\AntiCheat\Finding;
use App\Actions\AntiCheat\RuleConfig;
use App\Actions\AntiCheat\Scan;
use App\Enums\ScanScope;

/**
 * One detection rule. `DetectAbuse` resolves rules from the container, calls `detect()` once per chunk of the rule's scope
 * and writes whatever it yields. A rule reads the database and yields findings; it never writes.
 */
interface Rule
{
    public function scope(): ScanScope;

    /** @return iterable<Finding> */
    public function detect(RuleConfig $config, Scan $scan): iterable;
}
