<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\BroadcastAuthRequest;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Str;

/**
 * Signs a private channel subscription for the signed-in member. The channel rules live in
 * routes/channels.php; anything that is not a plain private channel gets the same 403.
 */
class BroadcastAuthController extends Controller
{
    public function __invoke(BroadcastAuthRequest $request): mixed
    {
        // The null and log drivers answer every channel with an empty 200 and no channel rules; never report that as success.
        abort_unless(in_array(config('broadcasting.connections.'.config('broadcasting.default').'.driver'), ['reverb', 'pusher', 'ably'], true), 503);

        $channel = $request->validated('channel_name');

        // Presence, encrypted and unprefixed names are not offered; Laravel would otherwise
        // normalise or sign some of them.
        abort_unless(
            Str::startsWith($channel, 'private-') && ! Str::startsWith($channel, 'private-encrypted-'),
            403,
        );

        return Broadcast::auth($request);
    }
}
