<?php

declare(strict_types=1);

/*
 * ZEF Framework — zone app-db-tx mutation-debt test doubles.
 *
 * Namespace-shadowed global functions for the Zef\Framework\Database
 * namespace: PHP resolves an UNQUALIFIED call inside a namespace to a
 * namespaced function when one exists, falling back to the global
 * otherwise. Declaring Zef\Framework\Database\hrtime and
 * Zef\Framework\Database\usleep lets the mutation-debt tests pin EXACT
 * values that the production code derives from them:
 *
 * - TransactionManager's cooperative hook-duration guard derives its
 *   elapsed_ms from two hrtime(true) reads (ceil of ns/1e6, strict
 *   greater-than against the threshold) — the real clock is neither
 *   stable nor observable at nanosecond boundaries;
 * - UnitOfWork::flushRetrying() derives its backoff sleep from
 *   policy->delayMs() * 1000 — the real sleep is slow and unobservable.
 *
 * Loaded by the suite bootstrap (tests/bootstrap.php) BEFORE any
 * Zef\Framework\Database call executes: PHP binds an unqualified call at
 * its first execution, so a late-loaded shadow would never intercept.
 * They are inert unless a test arms them via the globals below.
 *
 * Arming globals:
 *   $GLOBALS['__zef_fake_hrtime']    = ['fake' => callable(bool): int|float]
 *   $GLOBALS['__zef_db_usleep_calls'] = []   (recorded instead of sleeping)
 *
 * Only TransactionManager (hrtime) and UnitOfWork (usleep) make unqualified
 * calls in this namespace, so the shadows cannot affect any other class.
 */

namespace Zef\Framework\Database;

if (!\function_exists(__NAMESPACE__ . '\hrtime')) {
    function hrtime(bool $as_number = false): float|int
    {
        $armed = $GLOBALS['__zef_fake_hrtime'] ?? null;

        if (\is_array($armed) && \is_callable($armed['fake'] ?? null)) {
            $result = ($armed['fake'])($as_number);
            \assert(\is_int($result) || \is_float($result)); // nosemgrep: ban-qualified-global-call (the shadow narrows a fake-clock return for static analysis only — the qualified form cannot recurse here because this shadow's own name is hrtime, not assert; keeping the call visible to the pinned rules is unnecessary for a pure type guard)

            return $result;
        }

        // The only production caller in this namespace invokes hrtime(true);
        // the delegate therefore always returns the nanosecond number.
        return \hrtime(true); // nosemgrep: ban-qualified-global-call (the shadow MUST delegate with a leading backslash — an unqualified call here would recurse into this shadow itself)
    }
}

if (!\function_exists(__NAMESPACE__ . '\usleep')) {
    function usleep(int $microseconds): void
    {
        if (isset($GLOBALS['__zef_db_usleep_calls']) && \is_array($GLOBALS['__zef_db_usleep_calls'])) {
            $GLOBALS['__zef_db_usleep_calls'][] = $microseconds;

            return; // armed: deterministic, never actually sleep
        }

        \usleep($microseconds);
    }
}
