<?php

namespace App\Actions\Report;

use Illuminate\Support\Collection;

/** The open queue as listed, and how many targets with an eligible open report exist in all. */
final readonly class OpenQueue
{
    /** @param  Collection<int, array<string, mixed>>  $rows */
    public function __construct(public Collection $rows, public int $total) {}

    public function truncated(): bool
    {
        return $this->total > $this->rows->count();
    }
}
