<?php

/**
 * ZEF Framework — per-zone mutation-score ratchet gate.
 *
 * WHY THIS EXISTS
 * ---------------
 * The enforced mutation gate in this repository is AGGREGATE:
 * `composer mutation:ci` runs Infection with `--min-msi=85 --min-covered-msi=90`
 * over ~9.4k mutants. The owner's stated target ("MSI >= 95% per area/module")
 * was therefore measured by NO gate at all, and a zone could regress from 94%
 * to 60% while the aggregate gate stayed green.
 *
 * This script closes that gap WITHOUT re-running Infection in CI (which would
 * double the pipeline cost). It is a RATCHET over committed evidence:
 *
 *   baseline.tsv  the frozen floor per zone — the measured MSI at freeze time
 *   zones.tsv     the current measurement table
 *   scripts/f16_zones.tsv  the CANONICAL zone registry
 *
 * It fails when
 *   1. a canonical zone has no row in either table (the zone set must match);
 *   2. a row claiming OK is actually below --floor (default 95);
 *   3. a DEBT or UNKNOWN row carries no reason;
 *   4. any row has an empty `evidence` column;
 *   5. a zone's CURRENT msi is below its FROZEN baseline msi — the core check.
 *      A mutation score may not silently regress. Raising a baseline is a visible,
 *      reviewable edit to this directory; lowering one is the whole point of
 *      fixing tests.
 *
 * WHAT THIS GATE IS NOT
 * ---------------------
 * It does NOT measure mutation score, and it does NOT assert that every zone
 * meets the 95% target — it cannot, because at freeze time most zones do not.
 * It asserts that a measurement exists, that the recorded status is truthful, and
 * that no zone has gotten worse. A green run proves the RATCHET holds, not that
 * the code is well tested. Closing a zone to OK requires writing tests and
 * re-measuring, then promoting its row.
 *
 * Usage:
 *   php scripts/ci/assert-zone-coverage.php [--floor=95] [--table=PATH] [--baseline=PATH]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$options = getopt('', ['floor::', 'table::', 'baseline::', 'registry::']);
$floor = (float) ($options['floor'] ?? 95.0);
$tablePath = $root . '/' . (string) ($options['table'] ?? 'docs/mutation/zones.tsv');
$baselinePath = $root . '/' . (string) ($options['baseline'] ?? 'docs/mutation/baseline.tsv');
$registryPath = $root . '/' . (string) ($options['registry'] ?? 'scripts/f16_zones.tsv');

$fail = static function (string $message): never {
    fwrite(STDERR, "ZONE-COVERAGE FAIL: {$message}\n");
    exit(1);
};

if (!is_file($registryPath)) {
    $fail("canonical zone registry not found at {$registryPath}");
}
if (!is_file($tablePath)) {
    $fail(
        "measurement table not found at {$tablePath}. Expected one row per canonical zone (see docs/mutation/README.md for the format and how to regenerate it)."
    );
}
if (!is_file($baselinePath)) {
    $fail(
        "baseline table not found at {$baselinePath}. The ratchet has no floor without it — a missing baseline is a fail-closed condition, not a licence to skip the check."
    );
}

/**
 * Table columns (both tables, identical shape):
 *   zone | msi | covered_msi | total | status | evidence | reason
 * status: OK (msi >= floor) | DEBT (msi < floor) | UNKNOWN (no measurement)
 *
 * @return array<string, array<string, string>>
 */
$readTable = static function (string $path, callable $fail): array {
    $rows = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $lineNo => $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $cols = array_map('trim', explode('|', $line));
        if (count($cols) < 7) {
            $fail(sprintf(
                'malformed row %d in %s: expected 7 pipe-separated columns (zone|msi|covered_msi|total|status|evidence|reason), got %d',
                $lineNo + 1,
                $path,
                count($cols),
            ));
        }
        [$zone, $msi, $coveredMsi, $total, $status, $evidence, $reason] = $cols;
        $rows[$zone] = [
            'zone' => $zone,
            'msi' => $msi,
            'covered_msi' => $coveredMsi,
            'total' => $total,
            'status' => $status,
            'evidence' => $evidence,
            'reason' => $reason,
        ];
    }

    return $rows;
};

$registry = [];
foreach (file($registryPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $registry[] = explode('|', $line, 2)[0];
}
if ($registry === []) {
    $fail("canonical zone registry {$registryPath} contains no zones");
}

$current = $readTable($tablePath, $fail);
$baseline = $readTable($baselinePath, $fail);

foreach ([['current', $tablePath, $current], ['baseline', $baselinePath, $baseline]] as [$label, $path, $rows]) {
    $missing = array_values(array_diff($registry, array_keys($rows)));
    if ($missing !== []) {
        $fail("{$label} table {$path} is missing canonical zone(s): " . implode(', ', $missing));
    }
    $extra = array_values(array_diff(array_keys($rows), $registry));
    if ($extra !== []) {
        $fail("{$label} table {$path} has non-canonical row(s): " . implode(', ', $extra));
    }
}

$ok = $debt = $unknown = 0;
$regressions = [];
$belowTarget = [];

foreach ($registry as $zone) {
    $row = $current[$zone];
    $status = strtoupper($row['status']);

    if (!in_array($status, ['OK', 'DEBT', 'UNKNOWN'], true)) {
        $fail("zone {$zone}: status must be OK, DEBT or UNKNOWN, got '{$row['status']}'");
    }
    if ($row['evidence'] === '') {
        $fail("zone {$zone}: 'evidence' column is empty — a measurement needs a source");
    }
    if ($status === 'UNKNOWN') {
        if ($row['reason'] === '') {
            $fail("zone {$zone}: status UNKNOWN requires a written reason");
        }
        $unknown++;
    } else {
        if (!is_numeric($row['msi'])) {
            $fail("zone {$zone}: msi must be numeric for status {$status}, got '{$row['msi']}'");
        }
        $msi = (float) $row['msi'];
        if ($status === 'OK' && $msi + 1e-9 < $floor) {
            $fail(sprintf(
                'zone %s claims OK but measured MSI %.2f is below the floor %.2f — mark it DEBT with a reason, or lower the floor deliberately in the CI step',
                $zone,
                $msi,
                $floor,
            ));
        }
        if ($status === 'DEBT') {
            if ($row['reason'] === '') {
                $fail("zone {$zone}: status DEBT requires a written reason");
            }
            if ($msi + 1e-9 >= $floor) {
                $fail(sprintf(
                    'zone %s is marked DEBT but measured MSI %.2f already meets the floor %.2f — promote it to OK',
                    $zone,
                    $msi,
                    $floor,
                ));
            }
        }
        if ($status === 'OK') {
            $ok++;
        } else {
            $debt++;
        }
        if ($msi + 1e-9 < $floor) {
            $belowTarget[] = sprintf('%s=%.2f', $zone, $msi);
        }
    }

    // The ratchet: a measured zone may not fall below its frozen baseline.
    $base = $baseline[$zone];
    if (
        strtoupper($base['status']) !== 'UNKNOWN'
        && is_numeric($base['msi'])
        && is_numeric($row['msi'])
        && (float) $row['msi'] + 1e-9 < (float) $base['msi']
    ) {
        $regressions[] = sprintf(
            '%s: %.2f (baseline %.2f)',
            $zone,
            (float) $row['msi'],
            (float) $base['msi'],
        );
    }
}

printf(
    "Zone mutation ratchet: %d OK, %d DEBT, %d UNKNOWN of %d canonical zones — floor %.1f%%\n",
    $ok,
    $debt,
    $unknown,
    count($registry),
    $floor,
);

foreach ($registry as $zone) {
    $row = $current[$zone];
    printf(
        "  %-24s %-8s MSI=%-7s covered=%-7s mutants=%-6s %s\n",
        $zone,
        strtoupper($row['status']),
        $row['msi'],
        $row['covered_msi'],
        $row['total'],
        $row['reason'],
    );
}

if ($belowTarget !== []) {
    printf(
        "\nBelow the %.1f%% target (%d zone(s)): %s\n",
        $floor,
        count($belowTarget),
        implode(', ', $belowTarget),
    );
}

if ($regressions !== []) {
    fwrite(
        STDERR,
        "ZONE-COVERAGE FAIL: mutation score regressed below the frozen baseline for " . count($regressions) . " zone(s):\n  - "
        . implode("\n  - ", $regressions)
        . "\nEither restore the score, or raise the baseline in docs/mutation/baseline.tsv deliberately (a visible, reviewable edit).\n",
    );
    exit(1);
}

fwrite(STDOUT, "zone-coverage ratchet: PASSED\n");
exit(0);
