<?php

/**
 * ZEF Framework — coverage gate (composer coverage:gate, CI).
 *
 * Parses the PHPUnit clover report and fails when executable-statement
 * coverage drops below the required threshold (default 90%) OR when branch
 * (conditional) coverage drops below its own threshold (default 70%). This is
 * the enforcement mechanism behind the v2.13.0 hardening promise:
 * "all ZEF logic must be PHPUnit-testable, coverage >= 90%".
 *
 * Statement coverage alone is not sufficient: a suite can execute every line
 * while never taking the false arm of a condition, which is exactly the class
 * of gap mutation testing exists to catch. The branch assertion closes it at
 * the coverage layer.
 *
 * Usage: php scripts/ci/assert-coverage.php [required-percent] [clover-path] [required-branch-percent]
 */

declare(strict_types=1);

$cloverPath = $argv[2] ?? 'build/clover.xml';
$required   = (float) ($argv[1] ?? 90.0);
// Branch coverage is asserted separately from statement coverage. The two
// measure different things and a single number cannot stand for both: a suite
// can execute every line while never exercising the false arm of a condition.
// The default floor is the documented 70% standard; it is a CLI argument so
// raising it to the statement threshold is a one-line change once the measured
// value supports it (see the PR description for the measured baseline).
$requiredBranches = (float) ($argv[3] ?? 70.0);

if (!is_file($cloverPath)) {
    fwrite(STDERR, "coverage gate: clover report not found at {$cloverPath}\n");
    exit(2);
}

$xml = simplexml_load_file($cloverPath);
if ($xml === false) {
    fwrite(STDERR, "coverage gate: cannot parse {$cloverPath}\n");
    exit(2);
}

$metrics = $xml->xpath('//project/metrics');
if ($metrics === false || !isset($metrics[0])) {
    fwrite(STDERR, "coverage gate: no project metrics in {$cloverPath}\n");
    exit(2);
}

$total   = (int) $metrics[0]['statements'];
$covered = (int) $metrics[0]['coveredstatements'];

if ($total === 0) {
    fwrite(STDERR, "coverage gate: report contains zero statements\n");
    exit(2);
}

$pct = $covered / $total * 100.0;

printf(
    "Coverage gate: %.2f%% (%d/%d statements) — required: %.1f%%\n",
    $pct,
    $covered,
    $total,
    $required,
);

$failed = false;
if ($pct + 1e-9 < $required) {
    fwrite(STDERR, "coverage gate: statement coverage FAILED\n");
    $failed = true;
}

// Branch (conditional) coverage. Clover reports these as `conditionals` /
// `coveredconditionals` on the project metrics node. A report that carries no
// conditionals at all is treated as a fail-closed condition rather than a
// silent pass: "nothing to measure" is not the same as "everything covered".
$branches        = (int) ($metrics[0]['conditionals'] ?? 0);
$coveredBranches = (int) ($metrics[0]['coveredconditionals'] ?? 0);

if ($branches === 0) {
    fwrite(STDERR, "coverage gate: report contains zero conditionals — branch coverage cannot be asserted\n");
    exit(2);
}

$branchPct = $coveredBranches / $branches * 100.0;

printf(
    "Coverage gate: %.2f%% (%d/%d branches) — required: %.1f%%\n",
    $branchPct,
    $coveredBranches,
    $branches,
    $requiredBranches,
);

if ($branchPct + 1e-9 < $requiredBranches) {
    fwrite(STDERR, "coverage gate: branch coverage FAILED\n");
    $failed = true;
}

if ($failed) {
    fwrite(STDERR, "coverage gate: FAILED\n");
    exit(1);
}

fwrite(STDOUT, "coverage gate: PASSED\n");
exit(0);
