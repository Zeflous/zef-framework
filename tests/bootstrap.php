<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap for the whole suite (also used by Infection's initial
 * run and every mutant process).
 *
 * The namespace shadows for memory_get_usage()/usleep() must be defined
 * BEFORE the first test executes any Zef\Framework\Runtime code: PHP binds
 * an unqualified function call at its FIRST execution (the opcode's runtime
 * cache), so a shadow loaded later would never intercept. The shadows are
 * inert pass-throughs unless a test arms them via the documented globals —
 * see tests/Unit/runtime-sec-shadow-functions.php.
 */

require __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/Unit/runtime-sec-shadow-functions.php';

require_once __DIR__ . '/Unit/kernel-mid-shadow-functions.php';
