<?php

declare(strict_types=1);

/*
 * ZEF Framework — Adapters layer (runtime adapter)
 * Added in the v2.10.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Runtime;

/**
 * In-process REPL engine for `bin/zef tinker`.
 *
 * Each line is evaluated with persistent variable state between lines
 * (extract + get_defined_vars diff). Output per line is a var_export
 * string, truncated to $maxOutputLength. Errors are contained per line
 * and rendered as "[error] message" — the loop never dies on user input.
 *
 * Keywords 'exit'/'quit' stop the loop. Empty lines and lines starting
 * with '#' are skipped. Lines that do not end with ';' or '}' are wrapped
 * in `return ...;` so plain expressions echo their result.
 *
 * This class performs no I/O: callers pass lines in and receive outputs,
 * which keeps it unit-testable and lets bin/zef own the console aspect.
 */
final class TinkerSession
{
    /**
     * @param array<string, mixed> $state initial variables (e.g. app, container)
     */
    public function __construct(private array $state = [], private readonly int $maxOutputLength = 240) {}

    /**
     * Evaluate lines until exit/quit or end of input.
     *
     * @param list<string> $lines
     *
     * @return list<string> outputs, one per evaluated line (skips produce none)
     */
    public function run(array $lines): array
    {
        $outputs = [];
        foreach ($lines as $line) {
            // No rtrim("\r\n") here: trim() below already strips those (and
            // more), so an intermediate rtrim was behaviourally subsumed.
            $trimmed = trim((string) $line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            $lower = strtolower($trimmed);
            if ($lower === 'exit' || $lower === 'quit') {
                $outputs[] = 'bye.';

                break;
            }
            $outputs[] = $this->evaluate($trimmed);
        }

        return $outputs;
    }

    public function evaluate(string $line): string
    {
        $code = $this->wrapCode($line);
        $run = function () use ($code): mixed {
            extract($this->state, EXTR_SKIP); // never clobbers $code/$this internals
            unset($result);
            // nosemgrep: php.lang.security.eval-use — REPL feature: evaluates developer-supplied
            // code by design (local dev tool; input is never remote/untrusted).
            $result = eval($code); // nosemgrep: php.lang.security.eval-use
            $after = get_defined_vars();
            unset($after['code']);
            $state = $after;
            foreach ($state as $name => $value) {
                $this->state[$name] = $value;
            }

            return $result;
        };

        try {
            $result = $run();
            unset($this->state['result'], $this->state['state'], $this->state['after']);
        } catch (\Throwable $e) {
            unset($this->state['result'], $this->state['state'], $this->state['after']);

            return '[error] ' . $e::class . ': ' . $e->getMessage();
        }

        return $this->render($result);
    }

    /** Persist a variable into the session (e.g. set by the host). */
    public function set(string $name, mixed $value): void
    {
        $this->state[$name] = $value;
    }

    /** @return array<string,mixed> current state snapshot */
    public function snapshot(): array
    {
        return $this->state;
    }

    private function wrapCode(string $line): string
    {
        $last = substr(rtrim($line), -1);
        if ($last === ';' || $last === '}') {
            return $line;
        }

        return 'return ' . $line . ';';
    }

    private function render(mixed $result): string
    {
        $exported = 'null';
        if ($result !== null) {
            try {
                $exported = var_export($result, true);
            } catch (\Throwable) {
                $exported = '<unprintable:' . get_debug_type($result) . '>';
            }
        }
        if (strlen($exported) > $this->maxOutputLength) {
            return substr($exported, 0, $this->maxOutputLength) . '…';
        }

        return $exported;
    }
}
