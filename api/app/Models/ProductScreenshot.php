<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class ProductScreenshot extends Model
{
    use HasUlids;

    /** The table has created_at only. */
    const UPDATED_AT = null;

    protected $fillable = ['path', 'position', 'width', 'height', 'alt_text'];

    protected function casts(): array
    {
        return ['position' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function url(): string
    {
        return Storage::disk((string) config('launchpad.product_disk'))->url($this->path);
    }
}
