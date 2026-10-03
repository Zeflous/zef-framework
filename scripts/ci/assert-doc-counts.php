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
 * Audit v25 widened it to the counters restated in the other docs:
 *   - scripts/f16_zones.tsv <-> "docs/QUALITY.md (n zona kanonik)" and the README
 *                               canonical-zone count
 *   - first-party php -l    <-> "Linted <n> PHP files" (README + docs/QUALITY.md)
 *   - tests/fixtures.limit  <-> "fixture-count ratchet (<n> files)" (docs/GOVERNANCE.md)
 *   - phpstan-baseline.neon <-> "suppresses **<n>** findings" (docs/GOVERNANCE.md)
 *   - build/junit.xml       <-> "Tests: <n>," (docs/QUALITY.md) and "PHPUnit <n>"
 *                               (docs/GOVERNANCE.md) — skipped only when the
 *                               artifact is absent, like assert-release-docs.php
 *
 * It re-runs no test suite; it reads build/junit.xml only when present, so it is
 * cheap enough to run on every push. One-directional, like the sibling ratchets:
 * the docs follow the code, never the other way round.
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

// ---------------------------------------------------------------------------
// v25: deeper documentation counters.
// The layout tree above only covers README's src/tests/CHANGELOG/workflow
// counts. The remaining restated numbers drifted the same way (audit v25):
// QUALITY.md carried "Linted 861" against 896 real files, "Tests: 3636" against
// the live suite and "27 zona kanonik" against 36; GOVERNANCE.md carried
// "PHPUnit 3532", a "(191 files)" fixture ratchet and a "510 findings"
// baseline. Each is now derived live and the doc must state it.
$lintCount = (static function (string $rootDir): int {
    $skip = ['vendor', 'build', '.phpunit.cache', '.php-cs-fixer.cache'];
    $count = 0;
    $stack = [$rootDir];
    while (($dir = array_pop($stack)) !== null) {
        $entries = scandir($dir);
        if ($entries === false) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $rel = ltrim(substr($path, strlen($rootDir)), '/');
                if (in_array(explode('/', $rel)[0], $skip, true)) {
                    continue;
                }
                $stack[] = $path;
            } elseif (str_ends_with($entry, '.php')) {
                ++$count;
            }
        }
    }

    return $count;
})($root);

$zoneLines = file($root . '/scripts/f16_zones.tsv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$zoneCount = 0;
foreach ($zoneLines === false ? [] : $zoneLines as $zoneLine) {
    if (!str_starts_with(ltrim($zoneLine), '#')) {
        ++$zoneCount;
    }
}

$fixtureLimit = (int) trim((string) file_get_contents($root . '/tests/fixtures.limit'));

$baselineText = (string) file_get_contents($root . '/phpstan-baseline.neon');
$baselineLines = preg_split('/\R/', $baselineText);
if ($baselineLines === false) {
    $baselineLines = [];
}
$baselineEntries = 0;
foreach ($baselineLines as $baselineLine) {
    if (preg_match('/^\s*message:\s*\S/u', $baselineLine) === 1) {
        ++$baselineEntries;
    }
}

// The phpunit test count is restated in QUALITY.md and GOVERNANCE.md. It is a
// build artifact, so those two checks are skipped when build/junit.xml is absent
// — exactly like the sibling assert-release-docs.php gate.
$junitTests = 0;
$junitPath = $root . '/build/junit.xml';
if (is_file($junitPath)) {
    $junitXml = (string) file_get_contents($junitPath);
    if (preg_match('/<testsuite\b[^>]*\btests="(\d+)"/', $junitXml, $junitMatch) === 1) {
        $junitTests = (int) $junitMatch[1];
    }
}

if ($lintCount < 1 || $zoneCount < 1 || $fixtureLimit < 1) {
    $fail('A derived documentation count is zero — refusing to ratchet against an implausible layout.', [
        'lint' => $lintCount,
        'zones' => $zoneCount,
        'fixture limit' => $fixtureLimit,
    ]);
}

$docChecks = [
    ['QUALITY lint count', $lintCount, 'docs/QUALITY.md', '/Linted (\d+) PHP files/u'],
    ['QUALITY zone count', $zoneCount, 'docs/QUALITY.md', '/\((\d+) zona kanonik\)/u'],
    ['GOVERNANCE fixture ratchet', $fixtureLimit, 'docs/GOVERNANCE.md', '/fixture-count ratchet \((\d+) files\)/u'],
    ['GOVERNANCE phpstan baseline', $baselineEntries, 'docs/GOVERNANCE.md', '/suppresses \*\*(\d+)\*\* findings/u'],
    ['README lint count', $lintCount, 'README.md', '/Linted (\d+) PHP files/u'],
    ['README zone count', $zoneCount, 'README.md', '/f16_zones\.tsv`, (\d+) zona\)/u'],
];
if ($junitTests > 0) {
    $docChecks[] = ['QUALITY test count', $junitTests, 'docs/QUALITY.md', '/Tests: (\d+), Assertions:/u'];
    $docChecks[] = ['GOVERNANCE test count', $junitTests, 'docs/GOVERNANCE.md', '/PHPUnit (\d+) · PHPStan/u'];
}

foreach ($docChecks as [$docLabel, $docExpected, $docFile, $docPattern]) {
    $docText = (string) file_get_contents($root . '/' . $docFile);
    $docMatches = [];
    $docHits = preg_match_all($docPattern, $docText, $docMatches);
    if ($docHits !== 1) {
        $fail('A documentation file does not state the expected count exactly once.', [
            'check' => $docLabel,
            'file' => $docFile,
            'matches found' => $docHits,
        ]);
    }
    if ((int) $docMatches[1][0] !== $docExpected) {
        $fail('A documentation count drifted from the working tree.', [
            'check' => $docLabel,
            'file' => $docFile,
            'doc says' => $docMatches[1][0],
            'repo has' => $docExpected,
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
    echo sprintf(
        'DOC_COUNT_RATCHET_OK: README.md layout counts match the repo (src %d, tests %d, changelog %d, workflows %d)',
        $srcCount,
        $testCount,
        $changelogCount,
        $workflowCount,
    ), PHP_EOL;
}
