<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An export as the maker sees it. Never the file path, the disk or a URL: the client builds the download path itself.
 *
 * @mixin Export
 */
class ExportResource extends JsonResource
{
    private const FORMAT = 'Y-m-d\TH:i:s\Z';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'status' => $this->status->value,
            'created_at' => $this->created_at->clone()->utc()->format(self::FORMAT),
            'completed_at' => $this->completed_at?->clone()->utc()->format(self::FORMAT),
            'expires_at' => $this->expires_at?->clone()->utc()->format(self::FORMAT),
            'file_size' => $this->file_size,
            'row_counts' => $this->row_counts,
            'error' => $this->error,
        ];
    }
}
