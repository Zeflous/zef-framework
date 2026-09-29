<?php

declare(strict_types=1);

/**
 * Release cadence ratchet — tag ↔ changelog, and tag ≤ code.
 *
 * WHY THIS EXISTS
 * ---------------
 * Eleven minors (v2.18.0 → v2.27.0) shipped as `docs/CHANGELOG-v*.md` files
 * with no tag and no GitHub release (audit issue #88): the release-drafter
 * computed patch-only versions while the repository accumulated feature
 * batches, so the "documented release" references in README/SECURITY drifted
 * three to nine minors behind `ZefVersion`. A release that exists only as a
 * changelog file cannot be installed, attested or diffed — the tag IS the
 * release record. This gate makes the invariant that prevents that drift
 * class fail loudly on every push instead of being discovered at the next
 * audit.
 *
 * WHAT IT ASSERTS (fail-closed)
 * -----------------------------
 *   1. the set of `vX.Y.Z` tags on the remote is readable (`git ls-remote`);
 *   2. every tag with major >= 2 has a matching `docs/CHANGELOG-vX.Y.Z.md`
 *      in the working tree (one-directional on purpose: a changelog file
 *      WITHOUT a tag is a legitimate pre-release state — the version-bump PR
 *      lands its changelog first and the tag is pushed with the same merge);
 *   3. the newest tag never runs ahead of `ZefVersion::VERSION` — a tag that
 *      the code does not know about is a release cut out of order.
 *
 * v0.x tags are grandfathered (pre-integration history, no changelogs).
 *
 * EVIDENCE DISCIPLINE
 * -------------------
 * The gate is proven to FAIL on a violation (negative control) before it is
 * trusted. Controls run through a throwaway REPOSITORY harness (a temp repo
 * with its own `origin` remote, ZefVersion source and docs/ tree), so the
 * production code path itself is exercised — not an option that only tests
 * use: (1) a tag whose changelog file is absent fails the build; (2) a tag
 * that runs ahead of the tree's ZefVersion fails the build.
 *
 * Usage: php scripts/ci/assert-release-cadence.php [--json]
 */

$root = dirname(__DIR__, 2);

$options = getopt('', ['json::']);
$asJson = array_key_exists('json', $options);

/** Fail-closed exit. */
$fail = static function (string $reason, array $context = []) use ($asJson): never {
    if ($asJson) {
        echo json_encode(['status' => 'FAIL', 'reason' => $reason, 'context' => $context], JSON_PRETTY_PRINT), PHP_EOL;
    } else {
        fwrite(STDERR, "RELEASE_CADENCE_RATCHET_FAIL: {$reason}\n");
        foreach ($context as $key => $value) {
            fwrite(STDERR, "  {$key}: {$value}\n");
        }
    }
    exit(1);
};

// --- 1. read the remote tag set -------------------------------------------------

// The script lives in scripts/ci/ of the repository, so $root IS the repo —
// chdir there so git resolves the `origin` remote regardless of the caller's
// working directory (CI steps and local gate queues both qualify).
if (!is_dir($root . '/.git')) {
    $fail('script must run from inside a git clone (no .git at the repo root)', ['root' => $root]);
}
if (!chdir($root)) {
    $fail('could not chdir into the repository root', ['root' => $root]);
}

// The command is a single string LITERAL on purpose: the pinned SAST ruleset's
// exec-use rule flags every non-constant first argument to the exec family
// (exec/passthru/proc_open/popen/shell_exec/system/pcntl_exec — proc_open's
// array form included), and its only carve-out is a literal command. A remote
// tag query needs no dynamic parts: `origin` is fixed, the output is parsed,
// and errors surface as `fatal:` lines in the captured output below.
$ls = shell_exec('git ls-remote --tags origin 2>&1');
if (!is_string($ls) || $ls === '') {
    $fail('git ls-remote returned no tag references from origin');
}
if (str_contains($ls, 'fatal:') || str_contains($ls, 'ERROR:')) {
    $fail('git ls-remote failed against origin', ['output' => trim($ls)]);
}

$tags = [];
foreach (preg_split('/\R/', $ls) ?: [] as $line) {
    // Lines look like "<sha>\trefs/tags/v2.29.0"; peeled objects carry a ^{} suffix.
    if (preg_match('/refs\/tags\/(v\d+\.\d+\.\d+)$/', $line, $m) !== 1) {
        continue;
    }
    $tags[$m[1]] = true;
}
if ($tags === []) {
    $fail('no vX.Y.Z tags found on the remote — the release record is empty');
}

// --- 2. every major-2+ tag carries its changelog --------------------------------

$changelogDir = $root . '/docs';
$missing = [];
foreach (array_keys($tags) as $tag) {
    if (preg_match('/^v(\d+)\./', $tag, $m) !== 1) {
        continue;
    }
    if ((int) $m[1] < 2) {
        continue; // v0.x pre-integration history: grandfathered.
    }
    $changelog = $changelogDir . '/CHANGELOG-' . $tag . '.md';
    if (!is_file($changelog)) {
        $missing[] = $tag;
    }
}
if ($missing !== []) {
    sort($missing);
    $fail(
        'tag(s) without a changelog file — cut the changelog with the tag, in the same merge',
        [
            'missing' => implode(', ', $missing),
            'expected_dir' => $changelogDir,
        ]
    );
}

// --- 3. the newest tag never runs ahead of the code ------------------------------

$versionFile = $root . '/src/Domain/Foundation/ZefVersion.php';
if (!is_file($versionFile)) {
    $fail('ZefVersion source file not found', ['path' => $versionFile]);
}
$versionSource = (string) file_get_contents($versionFile);
if (preg_match("/VERSION\s*=\s*'(\d+\.\d+\.\d+)'/", $versionSource, $m) !== 1) {
    $fail('could not parse ZefVersion::VERSION from source', ['path' => $versionFile]);
}
$codeVersion = $m[1];

$newestTag = array_reduce(
    array_keys($tags),
    static function (string $carry, string $tag): string {
        return version_compare($tag, $carry, '>') ? $tag : $carry;
    },
    'v0.0.0'
);

if (version_compare($newestTag, 'v' . $codeVersion, '>')) {
    $fail(
        'newest tag runs ahead of ZefVersion::VERSION — the tag must ship in the same merge as the bump',
        [
            'newest_tag' => $newestTag,
            'code_version' => $codeVersion,
        ]
    );
}

// --- pass ------------------------------------------------------------------------

$result = [
    'status' => 'PASS',
    'tags' => count($tags),
    'newest_tag' => $newestTag,
    'code_version' => $codeVersion,
    'pending_changelogs' => count(array_filter(
        glob($changelogDir . '/CHANGELOG-v*.md') ?: [],
        static function (string $file) use ($tags): bool {
            $base = basename($file, '.md');
            $tag = substr($base, strlen('CHANGELOG-'));

            return !isset($tags[$tag]) && version_compare($tag, 'v2.0.0', '>=');
        }
    )),
];

if ($asJson) {
    echo json_encode($result, JSON_PRETTY_PRINT), PHP_EOL;
} else {
    printf(
        "RELEASE_CADENCE_RATCHET_OK: %d tags, newest %s (code %s)%s\n",
        $result['tags'],
        $newestTag,
        $codeVersion,
        $result['pending_changelogs'] > 0
            ? sprintf(' — %d changelog(s) awaiting their tag (pre-release state, allowed)', $result['pending_changelogs'])
            : ''
    );
}

exit(0);
