<?php

namespace App\Models;

use App\Enums\LinkKind;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** @property LinkKind $kind */
class UserLink extends Model
{
    use HasUlids;

    protected $fillable = ['kind', 'label', 'url', 'position'];

    protected function casts(): array
    {
        return ['kind' => LinkKind::class, 'position' => 'integer'];
    }
}
