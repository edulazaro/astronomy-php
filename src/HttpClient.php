<?php

namespace Astronomy;

use RuntimeException;

/**
 * The only thing the engine needs from the network: a GET that returns text.
 *
 * It is an interface so that the engine does not depend on any particular HTTP client. The default
 * is `NativeHttpClient`, which is bare PHP; an application can pass its own, and the tests pass one
 * that answers like Horizons without going out to the network.
 */
interface HttpClient
{
    /**
     * @param string $url
     * @return string The response body.
     *
     * @throws RuntimeException If the connection fails or the response is not a 200.
     */
    public function get(string $url): string;
}
