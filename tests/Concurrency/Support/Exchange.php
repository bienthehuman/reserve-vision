<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use JsonException;
use RuntimeException;

/**
 * One HTTP request a racer sends and what came back, with timings:
 *
 * - client: when the harness released the batch and when this response arrived
 *   (harness clock);
 * - server: X-Request-Start / X-Request-End, when the server started and
 *   finished handling it (server clock; set by ExposeRequestTiming only while
 *   CONCURRENCY_PROBE is on). Only these can prove the server ran the requests
 *   at the same time: a server that queues requests still accepts every
 *   connection at once, so client-side times always overlap.
 */
final class Exchange
{
    public int $status = 0;

    /** @var array<string, list<string>> lower-case name => values */
    public array $headers = [];

    public string $body = '';

    public float $released = 0.0;

    /** Seconds after the batch is released that this request is sent (0: with the others). See after(). */
    public float $delay = 0.0;

    public float $received = 0.0;

    /**
     * @param  array<string, scalar>  $payload  sent as JSON when $json, else as a form
     */
    public function __construct(
        public readonly Racer $racer,
        public readonly string $method,
        public readonly string $path,
        public readonly array $payload = [],
        public readonly bool $json = false,
    ) {}

    /**
     * Sends this request `$seconds` after the others in its batch, to sample different
     * orderings of two requests that both take the same lock (the archive race). The
     * server's own clock still has to show them overlapping (Race::assertRanConcurrently()).
     */
    public function after(float $seconds): self
    {
        $this->delay = $seconds;

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)][0] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        try {
            $data = json_decode($this->body, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("Expected JSON from {$this->method} {$this->path}, got HTTP {$this->status}: ".substr($this->body, 0, 300));
        }

        return is_array($data) ? $data : [];
    }

    public function message(): string
    {
        $message = $this->json()['message'] ?? '';

        return is_string($message) ? $message : '';
    }

    public function serverStart(): ?float
    {
        $value = $this->header('X-Request-Start');

        return is_numeric($value) ? (float) $value : null;
    }

    public function serverEnd(): ?float
    {
        $value = $this->header('X-Request-End');

        return is_numeric($value) ? (float) $value : null;
    }

    /** For failure messages. */
    public function describe(): string
    {
        return sprintf('%s %s %s → HTTP %d %s', $this->racer->label, $this->method, $this->path, $this->status, substr(trim($this->body), 0, 200));
    }
}
