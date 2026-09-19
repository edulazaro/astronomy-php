<?php

namespace Astronomy;

use RuntimeException;

/**
 * The default HTTP client, with no dependencies: cURL if the extension is there and, if not, PHP
 * streams.
 */
final class NativeHttpClient implements HttpClient
{
    /**
     * @param int $seconds How long to wait for a response. Horizons takes a few seconds for every
     * fifty thousand rows, and a response like that is over ten megabytes.
     */
    public function __construct(private readonly int $seconds = 300) {}

    /**
     * @param string $url
     * @return string
     *
     * @throws RuntimeException
     */
    public function get(string $url): string
    {
        return function_exists('curl_init') ? $this->withCurl($url) : $this->withStreams($url);
    }

    /**
     * @param string $url
     * @return string
     */
    private function withCurl(string $url): string
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT => $this->seconds,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);

        if (! is_string($body)) {
            throw new RuntimeException("Could not request {$url}: ".curl_error($curl));
        }

        if ($status !== 200) {
            throw new RuntimeException("{$url} answered {$status}.");
        }

        return $body;
    }

    /**
     * @param string $url
     * @return string
     */
    private function withStreams(string $url): string
    {
        $context = stream_context_create(['http' => ['timeout' => $this->seconds, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            throw new RuntimeException("Could not request {$url}.");
        }

        $headers = function_exists('http_get_last_response_headers') ? (http_get_last_response_headers() ?? []) : ($http_response_header ?? []);
        $status = preg_match('/^HTTP\/\S+\s+(\d{3})/', (string) ($headers[0] ?? ''), $parts) ? (int) $parts[1] : 0;

        if ($status !== 200) {
            throw new RuntimeException("{$url} answered {$status}.");
        }

        return $body;
    }
}
