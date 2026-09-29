<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.29.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a standalone ZEF application skeleton at an arbitrary
 * path (`bin/zef make:app <path>`). This is the in-repo substitute for a
 * separate `composer create-project` installer package: one command produces
 * a self-sufficient project (composer.json + composition root + Home module +
 * RR config + worker/web entrypoints) that boots, serves and passes PHPStan.
 *
 * Path safety rules (enforced through AppPathResolver):
 *   - absolute paths are used as-is; relative paths resolve against the
 *     FRAMEWORK root (deterministic, independent of the caller's cwd) and
 *     are lexically normalized (`..` segments are collapsed, so `../demo`
 *     escapes the framework root instead of being misread as nested);
 *     "absolute" follows the HOST platform: `/`-rooted on POSIX, drive or
 *     UNC roots on Windows, where both separator styles are accepted
 *     (issue #110: sys_get_temp_dir() returns backslash-separated paths);
 *   - the target must not exist, or must be an empty directory;
 *   - the target must live OUTSIDE the framework root (a standalone app
 *     nested inside the framework would break composer/path-repo layout) —
 *     checked against BOTH the lexical and the realpath'd form of the root,
 *     because Windows realpath() expands 8.3 short names (RUNNER~1 ->
 *     runneradmin) and a POSIX symlinked root aliases the same way;
 *   - the generated composer.json references the framework through a path
 *     repository computed from the LONGEST COMMON ANCESTOR of target and
 *     framework root (not merely `basename`), so deeply nested checkouts
 *     still resolve.
 *
 * File templates live in AppSkeleton (one renderer per scaffolded file).
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\InvalidNameException;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class AppGenerator implements GeneratorInterface
{
    private AppPathResolver $paths;

    private AppSkeleton $skeleton;

    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {
        $this->paths = $this->buildPathResolver();
        $this->skeleton = $this->buildSkeleton();
    }

    public function generate(?string $rawName, array $argv = []): int
    {
        $plan = $this->validatedPlan($rawName, $argv);
        if ($plan === null) {
            return 1;
        }

        $files = $this->skeleton->blueprint(
            $plan['target'],
            $plan['kebab'],
            $plan['pascal'],
            $plan['address'],
            $plan['frameworkRef'],
        );

        try {
            $this->writer->writeFiles($files);
        } finally {
            $this->io->out('');
        }

        $this->io->out(<<<TXT

            Standalone ZEF app '{$plan['kebab']}' scaffolded at {$plan['target']}.

            Next steps:
              1. composer install
              2. composer serve            # dev server on {$plan['address']}
                 vendor/bin/rr serve       # or RoadRunner production runtime
              3. curl http://localhost:8080/   (the Home module answers JSON)

            The skeleton is self-sufficient: app/Bootstrap.php is the composition
            root, modules/{$plan['pascal']} is your first hexagonal module. Read
            docs/TUTORIAL-CQRS-101.md in the framework repo for the full tour.

            TXT);

        return 0;
    }

    /** Collaborators are built through factories so the constructor stays wiring-free. */
    private function buildPathResolver(): AppPathResolver
    {
        return new AppPathResolver($this->root);
    }

    private function buildSkeleton(): AppSkeleton
    {
        return new AppSkeleton();
    }

    /**
     * Argument parsing + validation funnel: every rejection prints its own
     * usage/diagnostic line and yields null ("error already reported").
     *
     * @param array<int, mixed> $argv
     *
     * @return null|array{target: string, kebab: string, pascal: string, address: string, frameworkRef: string}
     */
    private function validatedPlan(?string $rawName, array $argv): ?array
    {
        $target = $this->resolveTargetArgument($rawName, $argv);
        if ($target === null) {
            return null;
        }

        return $this->resolvePlanPayload($argv, $target);
    }

    /**
     * The scaffold target path (--path option or the bare name argument);
     * null means "error already reported".
     *
     * @param array<int, mixed> $argv
     */
    private function resolveTargetArgument(?string $rawName, array $argv): ?string
    {
        $targetArg = $this->stringOption($argv, 'path') ?? $rawName;
        if ($targetArg === null || trim($targetArg) === '') {
            $this->io->err('Usage: bin/zef make:app <path> [--name=<project>] [--address=host:port]');

            return null;
        }

        return $this->resolveTarget(rtrim($targetArg, '/'));
    }

    /**
     * Project naming (--name or the target basename) plus the --address
     * option, assembled into the final plan; null means "error already
     * reported".
     *
     * @param array<int, mixed> $argv
     *
     * @return null|array{target: string, kebab: string, pascal: string, address: string, frameworkRef: string}
     */
    private function resolvePlanPayload(array $argv, string $target): ?array
    {
        $kebab = $this->stringOption($argv, 'name') ?? basename($target);

        try {
            $kebab = str_replace('_', '-', NamingRules::moduleName($kebab));
        } catch (InvalidNameException $e) {
            $this->io->err(str_replace('module name', 'project name', $e->getMessage()));

            return null;
        }

        $address = $this->resolvedAddress($argv);
        if ($address === null) {
            return null;
        }

        return [
            'target' => $target,
            'kebab' => $kebab,
            'pascal' => NamingRules::pascal($kebab),
            'address' => $address,
            'frameworkRef' => $this->paths->relativeFrameworkRef($target),
        ];
    }

    /**
     * --address option (or ZEF_HTTP_ADDRESS env, or 0.0.0.0:8080) with the
     * host:port grammar check; null means "error already reported".
     *
     * @param array<int, mixed> $argv
     */
    private function resolvedAddress(array $argv): ?string
    {
        $envAddress = getenv('ZEF_HTTP_ADDRESS');
        $address = $this->stringOption($argv, 'address')
            ?? (is_string($envAddress) && $envAddress !== '' ? $envAddress : '0.0.0.0:8080');
        // IPv4/IPv6-literal host:port — ASCII by design, \d is the concise
        // digit class (php:S6353).
        $ipv4Form = preg_match('/^[\d.]+:\d{1,5}$/', $address) === 1;
        $ipv6Form = preg_match('/^\[[\da-f:]+\]:\d{1,5}$/', $address) === 1;
        if (!$ipv4Form && !$ipv6Form) {
            $this->io->err("Invalid --address '{$address}'. Expected host:port (e.g. 0.0.0.0:8080).");

            return null;
        }

        return $address;
    }

    /** Resolve + validate the scaffold target; null means "error already reported". */
    private function resolveTarget(string $raw): ?string
    {
        $normalized = $this->paths->normalize($raw);
        $target = $this->paths->isAbsolute($normalized)
            ? $normalized
            : $this->paths->normalize(rtrim($this->root, '/') . '/' . ltrim($normalized, '/'));

        if (!$this->targetEscapesFrameworkRoot($target) || !$this->targetIsScaffoldable($target)) {
            return null;
        }

        return $target;
    }

    /**
     * Both the LEXICAL and the realpath'd root forms must reject the
     * target: on Windows realpath() expands 8.3 short names
     * (RUNNER~1 -> runneradmin) so a lexically-inside target would
     * otherwise compare unequal to the canonicalized root; on POSIX a
     * symlinked root aliases the same way (the reverse of #110).
     */
    private function targetEscapesFrameworkRoot(string $target): bool
    {
        $rootForms = [$this->paths->normalize($this->root)];
        $canonicalRoot = (string) realpath($this->root);
        if ($canonicalRoot !== '') {
            $rootForms[] = $this->paths->normalize($canonicalRoot);
        }

        foreach ($rootForms as $rootForm) {
            if ($target === $rootForm || str_starts_with($target, $rootForm . '/')) {
                $this->io->err("Refusing to scaffold INSIDE the framework root ({$target}). Choose a path outside it.");

                return false;
            }
        }

        return true;
    }

    /** A usable target is a missing path (the scaffold creates it) or an empty directory. */
    private function targetIsScaffoldable(string $target): bool
    {
        if (is_file($target)) {
            $this->io->err("Target path is a file, not a directory: {$target}");

            return false;
        }
        if (!is_dir($target)) {
            return true;
        }
        $entries = scandir($target);
        $isEmpty = $entries !== false && array_diff($entries, ['.', '..']) === [];
        if (!$isEmpty) {
            $this->io->err("Target directory exists and is not empty: {$target}");
        }

        return $isEmpty;
    }

    /** Extract the value of `--key=value` style options from argv. */
    /**
     * @param array<int, mixed> $argv
     */
    private function stringOption(array $argv, string $key): ?string
    {
        foreach ($argv as $arg) {
            if (!is_string($arg)) {
                continue;
            }
            if (str_starts_with($arg, "--{$key}=")) {
                $value = substr($arg, strlen($key) + 3);

                return $value === '' ? null : $value;
            }
        }

        return null;
    }
}
