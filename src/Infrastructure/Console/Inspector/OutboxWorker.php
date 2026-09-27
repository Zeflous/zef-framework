<?php

declare(strict_types=1);

/*
 * ZEF Framework — Console (Infrastructure layer: operational tooling)
 * Added in v2.31.0 (outbox:work — lease-based outbox relay worker).
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\EventSourcing\EventSourcingException;
use Zef\Framework\EventSourcing\OutboxRelay;

/**
 * `bin/zef outbox:work` — claim-based outbox relay worker (v2.31.0).
 *
 * The relay is injected via a factory closure from bin/zef (the composition
 * root), mirroring how Doctor receives its boot probe — this class stays
 * App-layer-independent (Deptrac) and is reusable from custom binaries:
 *
 *   $worker = new OutboxWorker(fn (): OutboxRelay => $container->get(OutboxRelay::class), $io);
 *
 * Options (parsed --key=value switches):
 *   --once            drain one claim batch and exit (cron/systemd timer style)
 *   --batch=<n>       entries claimed per cycle (default 100)
 *   --lease=<sec>     lease window per batch (default 30)
 *   --interval=<ms>   idle poll interval when a batch came back empty (default 500)
 *   --max=<n>         stop after n batches (default: run until stopped)
 *
 * SIGTERM/SIGINT (when the pcntl extension is loaded) release this worker's
 * leases and exit cleanly, so other workers take the unfinished entries
 * immediately instead of waiting out the lease window.
 */
final readonly class OutboxWorker
{
    /**
     * @param (\Closure(): OutboxRelay) $relayFactory builds the relay from the composition root
     * @param ConsoleIO                 $io          console output port
     */
    public function __construct(
        private \Closure $relayFactory,
        private ConsoleIO $io,
    ) {}

    /**
     * Run the worker loop.
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     *
     * @return int process exit code (0 = clean, 1 = relay error)
     */
    public function run(array $options): int
    {
        try {
            $relay = ($this->relayFactory)();
        } catch (\Throwable $e) {
            $this->io->err('outbox:work could not build the relay: ' . $e->getMessage());

            return 1;
        }

        $batch = $this->intOption($options, 'batch', 100);
        $lease = $this->intOption($options, 'lease', OutboxRelay::DEFAULT_LEASE_SECONDS);
        $intervalMs = $this->intOption($options, 'interval', 500, 0);
        $maxBatches = $this->nullableIntOption($options, 'max');
        $once = (bool) ($options['once'] ?? false);

        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $io = $this->io;
            $handleStop = static function (int $signal) use ($relay, $io, &$running): void {
                $released = $relay->releaseLease();
                $io->out("outbox:work signal {$signal} — released {$released} lease(s), exiting");
                $running = false;
            };
            if (defined('SIGTERM')) {
                pcntl_signal(SIGTERM, $handleStop);
            }
            if (defined('SIGINT')) {
                pcntl_signal(SIGINT, $handleStop);
            }
        }

        $processed = 0;
        $batches = 0;

        try {
            while (true) {
                $n = $relay->relayLeased($batch, $lease);
                $processed += $n;
                ++$batches;
                if ($once || ($maxBatches !== null && $batches >= $maxBatches)) {
                    break;
                }
                if ($n === 0 && $intervalMs > 0) {
                    usleep($intervalMs * 1000);
                }
                if (!$running) {
                    break;
                }
            }
        } catch (EventSourcingException $e) {
            $this->io->err('outbox relay failed: ' . $e->getMessage());

            return 1;
        }

        $this->io->out("outbox:work — {$processed} processed in {$batches} batch(es)");

        return 0;
    }

    /**
     * Resolve a parsed CLI switch to a bounded int, or the documented default.
     *
     * A bare flag (--lease with no =value) parses to bool true, which a plain
     * (int) cast would silently turn into 1 — a one-second lease where the
     * documented 30 was meant. Bare flags and explicit booleans therefore fall
     * back to the default instead of being cast (Kilo review, PR #178).
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     * @param int                        $default documented default used when the switch is absent or a bare flag
     * @param int                        $min     lower bound applied to a provided value
     */
    private function intOption(array $options, string $key, int $default, int $min = 1): int
    {
        $raw = $options[$key] ?? null;
        if ($raw === null || \is_bool($raw)) {
            return $default;
        }

        return max($min, (int) $raw);
    }

    /**
     * Same bare-flag semantics as {@see intOption()} for switches whose
     * documented default is "no bound" (--max).
     *
     * @param array<string, bool|string> $options parsed CLI switches (see class docblock)
     */
    private function nullableIntOption(array $options, string $key): ?int
    {
        $raw = $options[$key] ?? null;
        if ($raw === null || \is_bool($raw)) {
            return null;
        }

        return max(1, (int) $raw);
    }
}
