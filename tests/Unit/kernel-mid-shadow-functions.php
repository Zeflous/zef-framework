<?php

declare(strict_types=1);

/*
 * ZEF Framework — issue #90 round 4 (zone `ad-kernel-mid`) test doubles.
 *
 * Namespace-shadowed global functions: PHP resolves an UNQUALIFIED call
 * inside a namespace to a namespaced function when one exists, falling
 * back to the global otherwise. Declaring Zef\Framework\header() and
 * Zef\Framework\http_response_code() lets the mutation-debt tests observe
 * the SAPI side effects of ResponseEmitter::emit() — the CLI SAPI ignores
 * header() (headers_list() is always empty) and http_response_code() state
 * is process-global, so neither is assertable directly. The only other
 * root-namespace caller of these functions is ResponseEmitter itself;
 * AppGenerator calls them from Zef\Framework\Console\Generator, a nested
 * namespace that resolves to the global implementations, unaffected.
 *
 * The shadows are loaded by the suite bootstrap (tests/bootstrap.php) so
 * they exist BEFORE any Zef\Framework call executes: PHP binds an
 * unqualified call at its first execution, so a late-loaded shadow would
 * never intercept. They are inert unless a test arms them via the globals
 * below, so every other test keeps observing real behaviour. Both always
 * delegate to the global implementation with a leading backslash — an
 * unqualified call here would recurse into the shadow itself.
 *
 * Arming globals:
 *   $GLOBALS['__zef_header_calls'] = []         (appended per delegated call)
 *   $GLOBALS['__zef_status_calls'] = []         (appended per delegated call)
 *
 * The two delegating `\header(...)` calls below carry in-source nosemgrep
 * markers for `ban-qualified-global-call`: unlike the nineteen call sites that
 * rule normalised to the bare form, a bare call HERE would recurse into this
 * shadow itself — the qualified form is the only safe spelling, so the
 * disposition is a registered suppression instead of a rewrite.
 */

namespace Zef\Framework;

if (!\function_exists(__NAMESPACE__ . '\header')) {
    function header(string $header, bool $replace = true, ?int $response_code = null): void
    {
        if (isset($GLOBALS['__zef_header_calls']) && \is_array($GLOBALS['__zef_header_calls'])) {
            $GLOBALS['__zef_header_calls'][] = ['header' => $header, 'replace' => $replace];
        }

        if ($response_code === null) {
            \header($header, $replace); // nosemgrep: ban-qualified-global-call (the shadow MUST delegate with a leading backslash — an unqualified call here would recurse into this shadow itself; the arguments originate from the emitter under test, never from request input)

            return;
        }

        \header($header, $replace, $response_code); // nosemgrep: ban-qualified-global-call (same recursion-forced qualified form as above — pass-through delegation only)
    }
}

if (!\function_exists(__NAMESPACE__ . '\http_response_code')) {
    function http_response_code(?int $response_code = null): bool|int
    {
        if (isset($GLOBALS['__zef_status_calls']) && \is_array($GLOBALS['__zef_status_calls'])) {
            $GLOBALS['__zef_status_calls'][] = $response_code;
        }

        return $response_code === null
            ? \http_response_code()
            : \http_response_code($response_code);
    }
}
