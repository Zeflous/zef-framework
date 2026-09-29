<?php

declare(strict_types=1);

/*
 * ZEF Framework — Runtime (adapters layer).
 * POSIX signal handling extracted from RoadRunnerRuntime (php:S1448/S2042):
 * installs SIGTERM/SIGINT handlers (async delivery) that delegate to the
 * runtime's stop() callback, and restores the previous handlers on
 * shutdown. Behaviour-identical to the code previously inlined in
 * RoadRunnerRuntime::installSignals()/restoreSignals().
 */

namespace Zef\Framework\Runtime;

final class RuntimeSignalManager
{
    private bool $installed = false;

    /** @var list<int> */
    private array $owned = [];

    /** @param callable(): void $onSignal invoked for SIGTERM/SIGINT */
    public function __construct(
        private readonly bool $enabled,
        private readonly \Closure $onSignal,
    ) {
    }

    public function install(): void
    {
        if (
            !$this->enabled
            || $this->installed
            || !function_exists('pcntl_signal')
            || !function_exists('pcntl_async_signals')
        ) {
            return;
        }
        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            if (in_array($signal, $this->managedSignals(), true)) {
                ($this->onSignal)();
            }
        };
        foreach ($this->managedSignals() as $signal) {
            pcntl_signal($signal, $handler);
            $this->owned[] = $signal;
        }
        $this->installed = true;
    }

    public function restore(): void
    {
        if (!$this->installed || !function_exists('pcntl_signal')) {
            return;
        }
        foreach ($this->owned as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        $this->owned = [];
        $this->installed = false;
    }

    /** @return list<int> the SIGTERM/SIGINT pair available on this platform */
    private function managedSignals(): array
    {
        $signals = [];
        if (defined('SIGTERM')) {
            $signals[] = SIGTERM;
        }
        if (defined('SIGINT')) {
            $signals[] = SIGINT;
        }

        return $signals;
    }
}
