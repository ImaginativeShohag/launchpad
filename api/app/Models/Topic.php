<?php

namespace App\Models;

use Database\Factories\TopicFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Topic extends Model
{
    /** @use HasFactory<TopicFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['slug', 'name', 'emoji', 'description', 'position'];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }
}
