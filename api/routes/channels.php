<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// The bell: only the member themselves may listen. The live-count channel `product.{id}` is public (a plain
// Channel, App\Events\VoteCountUpdated), so it needs no authorisation and is not declared here. The ids are ULID strings: compare strictly.
Broadcast::channel('user.{id}', fn (User $user, string $id): bool => $user->getKey() === $id);
