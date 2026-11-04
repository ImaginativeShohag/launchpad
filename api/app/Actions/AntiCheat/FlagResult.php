<?php

namespace App\Actions\AntiCheat;

/** What one flagging pass did. */
final class FlagResult
{
    public function __construct(public int $flagged = 0, public int $unflagged = 0, public int $failedChunks = 0) {}
}
