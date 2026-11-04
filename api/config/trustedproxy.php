<?php

/*
 * Proxies whose X-Forwarded-* headers are believed (read by Laravel's TrustProxies middleware).
 *
 * TRUSTED_PROXIES is a comma-separated list of the load balancer's IPs or CIDR ranges. Unset means
 * "trust nothing", which is right when the app is reached directly. Behind a proxy it MUST be set,
 * otherwise every request appears to come from the proxy and all IP-keyed rate limits and ip_hash
 * values collapse into one. Anything that trusts every peer is deliberately dropped (`*`, `**`,
 * `REMOTE_ADDR`, any `/0` range): it would let any client spoof its IP and sidestep the limits.
 */
return [
    'proxies' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', ''))),
        fn (string $proxy): bool => $proxy !== ''
            && ! in_array(strtoupper($proxy), ['*', '**', 'REMOTE_ADDR'], true)
            && ! str_ends_with($proxy, '/0'),
    )),
];
