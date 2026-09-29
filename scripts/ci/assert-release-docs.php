<?php

declare(strict_types=1);

/**
 * Release docs ratchet — README/SECURITY version references ≤ ZefVersion.
 *
 * WHY THIS EXISTS
 * ---------------
 * Issue #87 (deep-audit at 79ac772): the user-facing docs misstated the
 * released version for nine minors — the README badge said v2.21.0 when the
 * code was v2.28.0, SECURITY.md promised "documented release v2.19.0" and
 * pointed security reporters at the wrong version record. These are the
 * first files a new reader and a security reporter see; a wrong version
 * there is a wrong statement about the supported line. The drift class is
 * the same one the release-cadence gate (issue #88) fixed for tags: a
 * version bump lands and the prose references silently stay behind.
 *
 * WHAT IT ASSERTS (fail-closed)
 * -----------------------------
 *   1. `ZefVersion::VERSION` is extractable from the source of truth;
 *   2. README.md carries the documented-release badge for exactly that
 *      version, the release-history summary block is re-baselined to it,
 *      and the per-release changelog links include it;
 *   3. SECURITY.md names it as the documented release and links its
 *      changelog as the version record.
 *
 * The gate is one-directional like the cadence gate: it pins the docs to
 * the code, never the code to the docs, so a version-bump PR that forgets
 * the docs fails THIS gate in the same PR instead of drifting.
 *
 * EVIDENCE DISCIPLINE
 * -------------------
 * Proven to FAIL before it is trusted: `--version=X.Y.Z` overrides the
 * extracted version for negative controls — running the gate with an
 * override that matches no reference must exit 1 (see the self-check
 * block in the commit that introduced it).
 *
 * Usage: php scripts/ci/assert-release-docs.php [--version=X.Y.Z] [--json]
 */

$root = dirname(__DIR__, 2);

$options = getopt('', ['version::', 'json::']);
$asJson = array_key_exists('json', $options);

/** Fail-closed exit. */
$fail = static function (string $reason, array $context = []) use ($asJson): never {
    if ($asJson) {
        echo json_encode(['status' => 'FAIL', 'reason' => $reason, 'context' => $context], JSON_PRETTY_PRINT), PHP_EOL;
    } else {
        fwrite(STDERR, "RELEASE_DOCS_RATCHET_FAIL: {$reason}\n");
        foreach ($context as $key => $value) {
            fwrite(STDERR, "  {$key}: {$value}\n");
        }
    }
    exit(1);
};

// 1. Source of truth: ZefVersion::VERSION.
$versionSource = (string) file_get_contents($root . '/src/Domain/Foundation/ZefVersion.php');
if (1 !== preg_match("/VERSION\s*=\s*'(\d+\.\d+\.\d+)'/", $versionSource, $m)) {
    $fail('Cannot extract ZefVersion::VERSION from the source of truth.', [
        'file' => 'src/Domain/Foundation/ZefVersion.php',
    ]);
}
$version = is_string($options['version'] ?? null) && '' !== $options['version'] ? (string) $options['version'] : $m[1];

// 2. README.md references.
$readme = (string) file_get_contents($root . '/README.md');
$readmeChecks = [
    'documented-release badge' => 'Rilis%20terdokumentasi-v' . $version,
    'release-history summary re-baselined' => 'Riwayat rilis selengkapnya (v2.8.0 → v' . $version . ')',
    'per-release changelog link' => 'CHANGELOG-v' . $version . '.md',
];
foreach ($readmeChecks as $label => $needle) {
    if (!str_contains($readme, $needle)) {
        $fail("README.md is missing the reference for version {$version}.", [
            'check' => $label,
            'expected to contain' => $needle,
        ]);
    }
}

// 3. SECURITY.md references.
$security = (string) file_get_contents($root . '/SECURITY.md');
$securityChecks = [
    'supported-versions row' => 'documented release **v' . $version . '**',
    'version-record link' => 'docs/CHANGELOG-v' . $version . '.md',
];
foreach ($securityChecks as $label => $needle) {
    if (!str_contains($security, $needle)) {
        $fail("SECURITY.md is missing the reference for version {$version}.", [
            'check' => $label,
            'expected to contain' => $needle,
        ]);
    }
}

// 4. Quantitative stats ratchet (issue #215 — second recurrence of #87's
//    drift class: the version half was pinned, the numbers were not, so the
//    badges drifted 43% behind while the release line looked healthy).
//
//    a) CLASS COUNT — deterministic, no execution needed: the number of
//       entries in the static classmap autoload/zef_autoload.php is the
//       zero-composer source of truth for "Kelas PSR-4". The README badge
//       and its alt text must carry exactly that number.
//
//    b) TEST COUNT — from build/junit.xml when present. CI's PHPUnit step
//       writes the JUnit log in the same job before this ratchet runs, so
//       on the protected branch the badge/table/comment can never drift
//       from the suite again. Locally the file usually does not exist;
//       the check then degrades to a notice instead of guessing from a
//       stale or absent run. Assertion and skip counts are intentionally
//       NOT asserted: they vary with the environment (a locally running
//       Redis on 6379 changes them by a couple of counts), while the test
//       count is stable everywhere.

$autoload = (string) file_get_contents($root . '/autoload/zef_autoload.php');
$classCount = (int) preg_match_all('/"[^"]+"\s*=>\s*__DIR__/', $autoload);
if ($classCount < 1) {
    $fail('Cannot count the static classmap entries.', [
        'file' => 'autoload/zef_autoload.php',
    ]);
}

if (1 !== preg_match('/Kelas%20PSR--4-(\d+)-/', $readme, $badgeClass)) {
    $fail('README.md does not carry the Kelas PSR-4 badge in the expected shape.', [
        'expected pattern' => 'Kelas%20PSR--4-<number>-',
    ]);
}
if ((int) $badgeClass[1] !== $classCount) {
    $fail('README.md class-count badge drifted from the static classmap.', [
        'badge says' => $badgeClass[1],
        'classmap entries' => $classCount,
        'source of truth' => 'autoload/zef_autoload.php',
    ]);
}
$expectedClassAlt = 'alt="' . $classCount . ' kelas"';
if (!str_contains($readme, $expectedClassAlt)) {
    $fail('README.md badge alt text does not match the classmap count.', [
        'expected to contain' => $expectedClassAlt,
    ]);
}

$junitPath = $root . '/build/junit.xml';
if (is_file($junitPath)) {
    $junit = simplexml_load_file($junitPath);
    $suite = $junit instanceof SimpleXMLElement ? ($junit->testsuite ?? null) : null;
    $suiteTests = $suite instanceof SimpleXMLElement ? (int) $suite['tests'] : 0;
    if ($suiteTests < 1) {
        $fail('Cannot read the test count from build/junit.xml.', [
            'file' => 'build/junit.xml',
        ]);
    }

    if (1 !== preg_match('/Test%20PHPUnit-(\d+)-/', $readme, $badgeTests)) {
        $fail('README.md does not carry the Test PHPUnit badge in the expected shape.', [
            'expected pattern' => 'Test%20PHPUnit-<number>-',
        ]);
    }
    if ((int) $badgeTests[1] !== $suiteTests) {
        $fail('README.md test-count badge drifted from the executed suite.', [
            'badge says' => $badgeTests[1],
            'junit reports' => $suiteTests,
            'source of truth' => 'build/junit.xml',
        ]);
    }

    $readmeTestRefs = [
        'badge alt text' => 'alt="' . $suiteTests . ' test"',
        'gate table row' => '<code>' . $suiteTests . '</code> test',
        'quickstart comment' => 'Tests: ' . $suiteTests . ',',
    ];
    foreach ($readmeTestRefs as $label => $needle) {
        if (!str_contains($readme, $needle)) {
            $fail("README.md test count ({$suiteTests}) is missing from the {$label}.", [
                'expected to contain' => $needle,
            ]);
        }
    }
} else {
    fwrite(STDERR, "RELEASE_DOCS_RATCHET_NOTE: build/junit.xml not found — test-count assertions skipped (run 'vendor/bin/phpunit --log-junit build/junit.xml' to enable).\n");
}

if ($asJson) {
    echo json_encode(['status' => 'OK', 'version' => $version, 'files' => ['README.md', 'SECURITY.md']]), PHP_EOL;
} else {
    echo "RELEASE_DOCS_RATCHET_OK: README.md and SECURITY.md carry version {$version} (matches ZefVersion::VERSION)", PHP_EOL;
}
