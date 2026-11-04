<?php

namespace App\Actions\AntiCheat;

/** What one member-level void call did. `hasMore` means the 100 per call cap left active votes on unfrozen days; call again. */
final readonly class VoidVotesResult
{
    public function __construct(public int $voided, public int $skippedFrozen, public int $failed, public bool $hasMore) {}
}
