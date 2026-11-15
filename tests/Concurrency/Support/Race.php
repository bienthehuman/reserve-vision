<?php

declare(strict_types=1);

namespace Tests\Concurrency\Support;

use Closure;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;

/**
 * A batch of racing requests, fired together, with the proof that they raced:
 * by the server's own clock, every request had started before the first one
 * finished, so all of them were inside the server at the same moment. If the
 * server ran them one after another, the race fails with a message saying so.
 */
final readonly class Race
{
    private const int BAR_WIDTH = 36;

    /**
     * @param  list<Exchange>  $exchanges
     */
    private function __construct(public string $title, public array $exchanges) {}

    /**
     * @param  list<Exchange>  $exchanges
     * @param  Closure(Exchange): string  $outcome  one line per request for the timeline
     */
    public static function run(Http $http, string $title, array $exchanges, Closure $outcome): self
    {
        $http->fire($exchanges);
        $race = new self($title, $exchanges);
        $race->print($outcome);
        $race->assertRanConcurrently();

        return $race;
    }

    /** @return list<Exchange> */
    public function withStatus(int $status): array
    {
        return array_values(array_filter($this->exchanges, fn (Exchange $e): bool => $e->status === $status));
    }

    /**
     * Fails unless the last request started (server clock) before the first
     * one finished. Client-side times can't show this: a server that queues
     * requests still accepts every connection at once.
     */
    public function assertRanConcurrently(): void
    {
        foreach ($this->exchanges as $exchange) {
            if ($exchange->serverStart() === null || $exchange->serverEnd() === null) {
                Assert::fail("No X-Request-Start/End headers on {$exchange->describe()}. The server must run with CONCURRENCY_PROBE=true (bin/test-concurrency sets it), or overlap can't be proven.");
            }
        }

        $lastStart = max(array_map(fn (Exchange $e): float => (float) $e->serverStart(), $this->exchanges));
        $firstEnd = min(array_map(fn (Exchange $e): float => (float) $e->serverEnd(), $this->exchanges));
        $count = count($this->exchanges);

        if ($lastStart < $firstEnd) {
            Assert::assertLessThan($firstEnd, $lastStart);

            return;
        }

        Assert::fail(sprintf(
            '%s: the server did NOT run the %d requests at the same time. The last one started %.1f ms after the first one had '
            .'already finished, so the server queued them. A server that runs requests one at a time can\'t double-book '
            .'whatever the code does, so this run proves nothing about the locks. Give the server more threads than racers '
            .'(CONCURRENCY_THREADS, now %s; see CLAUDE.md "Concurrency suite").',
            $this->title,
            $count,
            ($lastStart - $firstEnd) * 1000,
            getenv('CONCURRENCY_THREADS') ?: 'unknown',
        ));
    }

    /**
     * The timeline, by the server's clock: one bar per request from when the
     * server started it to when it finished. 0 ms is the first request in.
     *
     * @param  Closure(Exchange): string  $outcome
     */
    private function print(Closure $outcome): void
    {
        $starts = array_map(fn (Exchange $e): ?float => $e->serverStart(), $this->exchanges);
        $ends = array_map(fn (Exchange $e): ?float => $e->serverEnd(), $this->exchanges);

        if (in_array(null, $starts, true) || in_array(null, $ends, true)) {
            return; // assertRanConcurrently() explains.
        }

        /** @var list<float> $starts */
        /** @var list<float> $ends */
        $origin = min($starts);
        $span = max(max($ends) - $origin, 0.000001);
        $ms = fn (float $t): float => ($t - $origin) * 1000;

        $lines = ["\n  \e[1m{$this->title}\e[0m"];
        $lines[] = sprintf('  %-10s %-4s %-'.self::BAR_WIDTH.'s  %s', 'racer', 'HTTP', 'in the server (0 → '.number_format($span * 1000, 0).' ms)', 'outcome');

        foreach ($this->exchanges as $i => $exchange) {
            $from = (int) floor(($starts[$i] - $origin) / $span * self::BAR_WIDTH);
            $to = max($from + 1, (int) ceil(($ends[$i] - $origin) / $span * self::BAR_WIDTH));
            $bar = str_repeat(' ', $from).str_repeat('█', $to - $from).str_repeat(' ', self::BAR_WIDTH - $to);
            $color = $exchange->status === 201 ? "\e[32m" : "\e[33m";

            $lines[] = sprintf(
                "  %-10s %s%-4d\e[0m %s%s\e[0m  %6.1f → %6.1f ms  %s",
                $exchange->racer->label,
                $color,
                $exchange->status,
                $color,
                $bar,
                $ms($starts[$i]),
                $ms($ends[$i]),
                Str::limit($outcome($exchange), 56),
            );
        }

        $lastStart = max($starts);
        $firstEnd = min($ends);
        $lines[] = $lastStart < $firstEnd
            ? sprintf("  \e[32moverlap: all %d requests were in the server at once from %.1f to %.1f ms\e[0m", count($starts), $ms($lastStart), $ms($firstEnd))
            : sprintf("  \e[31mNO overlap: the last request started %.1f ms after the first had finished\e[0m", ($lastStart - $firstEnd) * 1000);

        fwrite(STDOUT, implode("\n", $lines)."\n");
    }
}
