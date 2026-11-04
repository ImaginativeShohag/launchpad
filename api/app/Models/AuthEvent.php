<?php

namespace App\Models;

use App\Enums\AuthEventType;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class AuthEvent extends Model
{
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['user_id', 'event', 'ip_hash', 'device_hash', 'user_agent', 'created_at'];

    protected function casts(): array
    {
        return ['event' => AuthEventType::class, 'created_at' => 'datetime'];
    }
}
