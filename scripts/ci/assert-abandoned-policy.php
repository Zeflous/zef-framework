<?php

/**
 * ZEF Framework — abandoned-package audit gate (composer audit).
 *
 * Enforces the composer.json "config.audit.abandoned" policy declared by
 * this project. The gate fails when:
 *
 *  1. the policy key is missing or set to an unknown value, or
 *  2. a package from the known-abandoned watchlist below is required
 *     without acknowledging it via config.audit.ignore-list.
 *
 * Values understood by Composer itself: "ignore" (silent), "report"
 * (warn, exit 0) and "fail" (non-zero exit). ZEF pins "report": upgrades
 * stay visible without breaking CI on third-party renames.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$composerPath = $root . '/composer.json';

$fail = static function (string $message): never {
    fwrite(STDERR, "AUDIT FAIL: {$message}\n");
    exit(1);
};

$raw = file_get_contents($composerPath);
if ($raw === false) {
    $fail("composer.json not readable at {$composerPath}");
}

try {
    $composer = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail('composer.json is not valid JSON: ' . $e->getMessage());
}

if (!is_array($composer)) {
    $fail('composer.json did not decode to an object');
}

$policy = $composer['config']['audit']['abandoned'] ?? null;
$known = ['ignore', 'report', 'fail'];
if (!is_string($policy) || !in_array($policy, $known, true)) {
    $fail(sprintf(
        'config.audit.abandoned must be one of [%s], got %s. ZEF policy requires the literal value "report".',
        implode(', ', $known),
        var_export($policy, true),
    ));
}

/*
 * Known-abandoned watchlist: packages that Composer has flagged as
 * abandoned in the past and that this project must not adopt silently.
 * Any hit must be acknowledged under config.audit.ignore-list.
 */
$watchlist = [
    'phpunit/php-token-stream',
    'symfony/monolog-bridge',
    'nommyde/buggregator',
    'sonata-project/exporter',
    'laminas/laminas-zendframework-bridge',
];

$ignoreList = $composer['config']['audit']['ignore-list'] ?? [];
if (!is_array($ignoreList)) {
    $fail('config.audit.ignore-list must be an array of package names');
}

$required = [];
foreach (['require', 'require-dev'] as $section) {
    foreach (array_keys($composer[$section] ?? []) as $name) {
        $required[strtolower((string) $name)] = $section;
    }
}

$hits = [];
foreach ($watchlist as $package) {
    $key = strtolower($package);
    if (isset($required[$key]) && !in_array($package, $ignoreList, true)) {
        $hits[] = sprintf('%s (section: %s)', $package, $required[$key]);
    }
}
if ($hits !== []) {
    $fail('abandoned package(s) required without ignore-list acknowledgement: ' . implode('; ', $hits));
}

/*
 * Time-boxed allowlist enforcement.
 *
 * scripts/ci/abandoned-allowlist.json declares which abandoned packages are
 * tolerated, why, and until when. It was previously never read by any code:
 * the file documented a policy that nothing enforced, so an entry could sit
 * past its expiry indefinitely and a new abandoned package could be added to
 * the allowlist without the gate noticing. This block makes the file load-
 * bearing:
 *
 *   1. every entry must carry a parseable `expires` date;
 *   2. an entry past its expiry fails the build (forcing a re-review);
 *   3. an allowlisted package that is NOT actually required is reported, so
 *      the allowlist cannot accumulate stale entries.
 */
$allowlistPath = $root . '/scripts/ci/abandoned-allowlist.json';
if (!is_file($allowlistPath)) {
    $fail("abandoned allowlist not found at {$allowlistPath} — the policy file is required");
}

$allowlistRaw = file_get_contents($allowlistPath);
if ($allowlistRaw === false) {
    $fail("abandoned allowlist not readable at {$allowlistPath}");
}

try {
    $allowlist = json_decode($allowlistRaw, true, 512, JSON_THROW_ON_ERROR);
} catch (\JsonException $e) {
    $fail('abandoned allowlist is not valid JSON: ' . $e->getMessage());
}

$allowed = $allowlist['allowed'] ?? null;
if (!is_array($allowed)) {
    $fail('abandoned allowlist must contain an "allowed" array');
}

$today = new \DateTimeImmutable('today');
$expired = [];
$stale = [];
$active = 0;

/*
 * The "is it still installed?" question must be asked of the LOCK file, not of
 * composer.json. An abandoned package is typically a TRANSITIVE dependency
 * (the allowlist's own entry is doctrine/annotations, pulled in by
 * phpbench/phpbench), so it never appears in require/require-dev and a
 * direct-dependency check would report every legitimate entry as stale.
 * composer.lock lists the resolved set, which is what actually ships.
 */
/**
 * Lowercased package names present in composer.lock (packages + packages-dev).
 * A missing lock or an unreadable file yields an empty set; a malformed lock
 * fails the audit through $fail. Extracted so the top-level nesting stays flat.
 *
 * @param callable(string): never $fail
 * @return array<string, true>
 */
function installedPackagesFromLock(string $lockPath, callable $fail): array
{
    $installed = [];
    // A missing OR an unreadable lock file both yield the empty set on
    // purpose (merged single early return — S1142 counts return statements):
    // neither condition is evidence that an allowlist entry is stale, and
    // only a lock that is present AND readable but MALFORMED fails below.
    $lockRaw = is_file($lockPath) ? file_get_contents($lockPath) : false;
    if ($lockRaw === false) {
        return $installed;
    }
    try {
        $lock = json_decode($lockRaw, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $e) {
        $fail('composer.lock is not valid JSON: ' . $e->getMessage());

        return $installed;
    }
    foreach (['packages', 'packages-dev'] as $section) {
        foreach (($lock[$section] ?? []) as $package) {
            if (isset($package['name']) && is_string($package['name'])) {
                $installed[strtolower($package['name'])] = true;
            }
        }
    }

    return $installed;
}

$lockPath = $root . '/composer.lock';
$installed = installedPackagesFromLock($lockPath, $fail);

foreach ($allowed as $index => $entry) {
    if (!is_array($entry) || !isset($entry['name']) || !is_string($entry['name'])) {
        $fail(sprintf('abandoned allowlist entry #%d must be an object with a string "name"', $index));
    }

    $name = $entry['name'];
    $expires = $entry['expires'] ?? null;
    if (!is_string($expires) || $expires === '') {
        $fail(sprintf('abandoned allowlist entry "%s" must carry an "expires" date (YYYY-MM-DD)', $name));
    }

    $expiry = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires);
    if ($expiry === false) {
        $fail(sprintf('abandoned allowlist entry "%s" has an unparseable expires date: %s', $name, $expires));
    }

    if ($expiry < $today) {
        $expired[] = sprintf('%s (expired %s)', $name, $expires);
        continue;
    }

    // Stale = no longer present in the resolved dependency set at all. When the
    // lock file is unavailable the check is skipped rather than guessed: a
    // missing lock file is not evidence that an entry is stale.
    if ($installed !== [] && !isset($installed[strtolower($name)])) {
        $stale[] = $name;
        continue;
    }

    $active++;
}

if ($expired !== []) {
    $fail(
        'abandoned allowlist entr(ies) past their expiry — re-review and either renew with a new date or remove: '
        . implode('; ', $expired)
    );
}

if ($stale !== []) {
    $fail(
        'abandoned allowlist entr(ies) no longer present in composer.lock — remove them: '
        . implode('; ', $stale)
    );
}

fwrite(STDOUT, sprintf(
    "Audit OK: abandoned-policy=%s, %d package(s) checked, 0 unacknowledged watchlist hits, %d active allowlist entr(ies), 0 expired.\n",
    $policy,
    count($required),
    $active,
));
exit(0);
