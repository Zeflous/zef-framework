<?php

declare(strict_types=1);

/**
 * Documentation count ratchet — README structural counts vs the real repo.
 *
 * WHY THIS EXISTS
 * ---------------
 * Audit v24: the README "Struktur Proyek" tree and the CI/CD prose carried
 * counters that had drifted for dozens of merges (src 360 vs 640, tests 90 vs
 * 222, CHANGELOG 25 vs 42, workflows 13 vs 20) while the release-history
 * summary contradicted the tree about the CHANGELOG count. The version, class
 * and test counts were already pinned by assert-release-docs.php; the counts
 * that describe the repository layout were not, so nothing failed when they
 * fell behind.
 *
 * WHAT IT ASSERTS (fail-closed)
 * -----------------------------
 * Four counts are derived from the working tree and must equal the number the
 * README states in every place it states it:
 *   - src/**.php            <-> "├── src/ ... # <n> berkas PHP"
 *   - tests/**.php          <-> "├── tests/ ... # <n> berkas PHP"
 *   - docs/CHANGELOG-*.md   <-> "├── docs/ ... # <n> CHANGELOG"
 *                               AND "<n> berkas CHANGELOG" (release summary)
 *   - .github/workflows/*   <-> "└── .github/workflows/ ... # <n> workflow"
 *                               AND "<n> workflow pada" (CI/CD prose)
 *
 * It re-runs no test suite and reads no build artifact, so it is cheap enough
 * to run on every push. One-directional, like the sibling ratchets: the docs
 * follow the code, never the other way round.
 *
 * Usage: php scripts/ci/assert-doc-counts.php [--json]
 */
$root = dirname(__DIR__, 2);

$options = getopt('', ['json::']);
$asJson = array_key_exists('json', $options);

/** Fail-closed exit. */
$fail = static function (string $reason, array $context = []) use ($asJson): never {
    if ($asJson) {
        echo json_encode(['status' => 'FAIL', 'reason' => $reason, 'context' => $context], JSON_PRETTY_PRINT), PHP_EOL;
    } else {
        fwrite(STDERR, "DOC_COUNT_RATCHET_FAIL: {$reason}\n");
        foreach ($context as $key => $value) {
            fwrite(STDERR, "  {$key}: " . (is_scalar($value) ? (string) $value : json_encode($value)) . "\n");
        }
    }

    exit(1);
};

$countPhp = static function (string $dir): int {
    if (!is_dir($dir)) {
        return 0;
    }
    $count = 0;
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            ++$count;
        }
    }

    return $count;
};

$srcCount = $countPhp($root . '/src');
$testCount = $countPhp($root . '/tests');
$changelogFiles = glob($root . '/docs/CHANGELOG-*.md');
$changelogCount = $changelogFiles === false ? 0 : count($changelogFiles);
$workflowYml = glob($root . '/.github/workflows/*.yml');
$workflowYaml = glob($root . '/.github/workflows/*.yaml');
$workflowCount = ($workflowYml === false ? 0 : count($workflowYml))
    + ($workflowYaml === false ? 0 : count($workflowYaml));

if ($srcCount < 1 || $testCount < 1 || $changelogCount < 1 || $workflowCount < 1) {
    $fail('A derived count is zero — refusing to ratchet against an implausible layout.', [
        'src' => $srcCount,
        'tests' => $testCount,
        'changelog' => $changelogCount,
        'workflows' => $workflowCount,
    ]);
}

$readme = (string) file_get_contents($root . '/README.md');

/**
 * Each entry: label, derived count, and the README regex whose first capture
 * group must equal it. The regex must match exactly once, so a reworded README
 * fails loudly instead of silently skipping a check.
 */
$checks = [
    ['src tree', $srcCount, '/├── src\/\s+#\s+(\d+) berkas PHP/u'],
    ['tests tree', $testCount, '/├── tests\/\s+#\s+(\d+) berkas PHP/u'],
    ['changelog tree', $changelogCount, '/├── docs\/\s+#\s+(\d+) CHANGELOG/u'],
    ['changelog summary', $changelogCount, '/(\d+) berkas CHANGELOG/u'],
    ['workflow tree', $workflowCount, '/└── \.github\/workflows\/\s+#\s+(\d+) workflow/u'],
    ['workflow prose', $workflowCount, '/^(\d+) workflow pada/m'],
];

foreach ($checks as [$label, $expected, $pattern]) {
    $matches = [];
    $hits = preg_match_all($pattern, $readme, $matches);
    if ($hits !== 1) {
        $fail('README.md does not state the expected count exactly once.', [
            'check' => $label,
            'pattern' => $pattern,
            'matches found' => $hits,
        ]);
    }
    if ((int) $matches[1][0] !== $expected) {
        $fail('README.md count drifted from the working tree.', [
            'check' => $label,
            'README says' => $matches[1][0],
            'repo has' => $expected,
        ]);
    }
}

if ($asJson) {
    echo json_encode([
        'status' => 'OK',
        'counts' => [
            'src' => $srcCount,
            'tests' => $testCount,
            'changelog' => $changelogCount,
            'workflows' => $workflowCount,
        ],
    ]), PHP_EOL;
} else {
    echo 'DOC_COUNT_RATCHET_OK: README.md layout counts match the repo '
        . "(src {$srcCount}, tests {$testCount}, changelog {$changelogCount}, workflows {$workflowCount})", PHP_EOL;
}
