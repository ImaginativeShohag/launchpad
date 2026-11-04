<?php

namespace Tests;

use ArrayObject;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Broadcast;
use Psr\Http\Message\RequestInterface;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The suite pins BROADCAST_CONNECTION=reverb, but no test may open a socket to a Reverb server: the Pusher HTTP
        // client gets a stub that answers 200 and records every request it was asked to send (`app('test.reverb.requests')`).
        $requests = new ArrayObject;
        $this->app->instance('test.reverb.requests', $requests);
        config(['broadcasting.connections.reverb.client_options.handler' => function (RequestInterface $request) use ($requests) {
            $requests->append($request);

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], '{}'));
        }]);

        // Booting the app already built the driver (routes/channels.php registers a channel on it) with the real HTTP
        // client, so rebuild it with the stub and register the channels again.
        Broadcast::purge('reverb');
        require base_path('routes/channels.php');
    }
}
