<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use CurlHandle;
use RuntimeException;

/**
 * Fires a batch of requests at a running server, all in flight at once:
 * every curl handle is built and added to one curl_multi first, and only then
 * released together. Each racer's cookies go out with its requests and the
 * cookies that come back are kept.
 */
final readonly class Http
{
    public function __construct(public string $baseUrl) {}

    /**
     * @param  list<Exchange>  $exchanges  filled in place: status, headers, body, timings
     */
    public function fire(array $exchanges): void
    {
        $multi = curl_multi_init();
        /** @var array<int, Exchange> $byHandle */
        $byHandle = [];

        // 1. Prepare everything. A request with a delay (Exchange::after()) is built now but added later.
        /** @var list<array{float, CurlHandle}> $pending */
        $pending = [];
        foreach ($exchanges as $exchange) {
            $handle = $this->handle($exchange);
            $byHandle[spl_object_id($handle)] = $exchange;

            if ($exchange->delay > 0.0) {
                $pending[] = [$exchange->delay, $handle];
            } else {
                curl_multi_add_handle($multi, $handle);
            }
        }

        // 2. Release them together.
        $released = microtime(true);
        foreach ($exchanges as $exchange) {
            $exchange->released = $released;
        }

        do {
            foreach ($pending as $i => [$delay, $handle]) {
                if (microtime(true) - $released >= $delay) {
                    curl_multi_add_handle($multi, $handle);
                    unset($pending[$i]);
                }
            }

            $status = curl_multi_exec($multi, $running);

            while (($done = curl_multi_info_read($multi)) !== false) {
                /** @var CurlHandle $handle */
                $handle = $done['handle'];
                $exchange = $byHandle[spl_object_id($handle)];
                $exchange->received = microtime(true);

                if ($done['result'] !== CURLE_OK) {
                    throw new RuntimeException("{$exchange->method} {$this->baseUrl}{$exchange->path} failed: ".curl_error($handle).'. Is the concurrency server running? (bin/test-concurrency starts it)');
                }

                $exchange->status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $body = curl_multi_getcontent($handle);
                $exchange->body = is_string($body) ? $body : '';
                $exchange->racer->absorb($exchange);
                curl_multi_remove_handle($multi, $handle);
            }

            if ($running > 0) {
                curl_multi_select($multi, $pending === [] ? 0.01 : 0.0005);
            } elseif ($pending !== []) {
                usleep(200);
            }
        } while (($running > 0 || $pending !== []) && $status === CURLM_OK);

        curl_multi_close($multi);
    }

    private function handle(Exchange $exchange): CurlHandle
    {
        $handle = curl_init($this->baseUrl.$exchange->path);

        $headers = ['Accept: '.($exchange->json ? 'application/json' : 'text/html')];
        if (($cookies = $exchange->racer->cookieHeader()) !== null) {
            $headers[] = "Cookie: {$cookies}";
        }
        if (($token = $exchange->racer->xsrfToken()) !== null) {
            $headers[] = "X-XSRF-TOKEN: {$token}";
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => $exchange->method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => function (CurlHandle $unused, string $line) use ($exchange): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $exchange->headers[strtolower(trim($name))][] = trim($value);
                }

                return strlen($line);
            },
        ];

        if ($exchange->method !== 'GET') {
            if ($exchange->json) {
                $headers[] = 'Content-Type: application/json';
                $options[CURLOPT_POSTFIELDS] = json_encode($exchange->payload, JSON_THROW_ON_ERROR);
            } else {
                $options[CURLOPT_POSTFIELDS] = http_build_query($exchange->payload);
            }
        }

        $options[CURLOPT_HTTPHEADER] = [...$headers, 'Expect:'];
        curl_setopt_array($handle, $options);

        return $handle;
    }
}
