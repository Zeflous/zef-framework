<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef config:show [key] [--reveal]` — dumps the aggregated
 * configuration of the (already booted) app. Provider configs routinely
 * contain Closures (factories), so the JSON encoder replaces non-serialisable
 * values with deterministic placeholders instead of throwing.
 *
 * Secret masking (issue #214): values under secret-looking keys (password,
 * secret, token, credential, api-key, ...) render as `****(<len>)` by default,
 * so a recorded terminal session, a CI log capture, or a config pasted into
 * an issue cannot leak live credentials. `--reveal` prints values verbatim,
 * but refuses to run while ZEF_ENV=production — conscious debugging only.
 *
 * Authoritative masking (issue #355 C-3): name heuristics alone miss secrets
 * stored under innocent-looking keys. When the caller supplies the app's
 * loaded config bag, its `secretPaths()` map — the loader's exact record of
 * which leaves were resolved from `%secret:%` references — is masked too,
 * including any subtree asked for BY a parent key. Heuristics remain active
 * as belt-and-braces for providers that bypass the loader.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Config\ConfigAggregator;
use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Observability\TelemetrySanitizer;

final readonly class ConfigShower
{
    /** Sentinel distinguishes "key missing" from a legitimately stored null. */
    private const string MISSING = '__zef_config_missing__';

    /**
     * Config-surface needles on top of TelemetrySanitizer::isSensitiveKey().
     * The config tree is richer than telemetry attributes (db credentials,
     * signing keys, api scopes), so the canonical matcher is widened with
     * credential/key compounds. Compound forms avoid masking unrelated words
     * that merely contain a short substring (e.g. a `monkey` key).
     */
    private const array SECRET_KEYS = [
        'credential', 'passphrase',
        'access-key', 'access_key', 'private-key', 'private_key',
        'signing-key', 'signing_key', 'client-key', 'client_key',
    ];

    /**
     * @param list<string> $secretPaths authoritative dotted paths resolved from
     *                                  `%secret:%` references (from the app's
     *                                  Config bag via `secretPaths()`); empty
     *                                  when the bag is unavailable — masking
     *                                  then falls back to name heuristics only
     */
    public function __construct(
        private ConfigAggregator $aggregator,
        private ConsoleIO $io,
        private ?EnvInterface $env = null,
        private array $secretPaths = [],
    ) {}

    public function run(?string $key, bool $reveal = false): int
    {
        if ($reveal && $this->isProduction()) {
            $this->io->err('Refusing --reveal with ZEF_ENV=production (config:show would print secret values).');

            return 1;
        }
        if ($key === null) {
            $this->showAll($reveal);

            return 0;
        }

        return $this->showKey($key, $reveal);
    }

    private function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function showAll(bool $reveal): void
    {
        $this->io->out($this->encode($this->maskTree($this->aggregator->all(), $reveal, '')));
    }

    private function showKey(string $key, bool $reveal): int
    {
        $value = $this->aggregator->get($key, self::MISSING);
        if ($value === self::MISSING) {
            $this->io->err("Config key '{$key}' is not set.");

            return 1;
        }

        $this->io->out($this->encode(
            $reveal || !$this->isMaskedPath($key)
                ? $this->maskTree($value, $reveal, $key)
                : $this->mask($value),
        ));

        return 0;
    }

    /**
     * Path-aware safe rendering: `$prefix` is the dotted path the value sits
     * at (root dump passes ''), so a leaf whose path is an authoritative
     * secret — or lives inside one — is masked even when its key name looks
     * innocent (issue #355 C-3).
     */
    private function maskTree(mixed $value, bool $reveal, string $prefix): mixed
    {
        if (!$reveal && $this->inSecretSubtree($prefix)) {
            return $this->mask($value);
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                // (string) is a no-op post PHP array-key normalisation; kept for
                // JSON key stability. @infection-ignore-all
                $key = (string) $k;
                $path = $prefix === '' ? $key : $prefix . '.' . $key;
                $out[$key] = !$reveal && $this->isMaskedPath($path)
                    ? $this->mask($v)
                    : $this->maskTree($v, $reveal, $path);
            }

            return $out;
        }

        return match (true) {
            $value instanceof \Closure => '<closure>',
            is_object($value) => '<object ' . $value::class . '>',
            is_resource($value) => '<resource>',
            default => $value,
        };
    }

    /** Does this dotted config path point at a secret-looking slot? */
    private function pathIsSecret(string $dottedKey): bool
    {
        return array_any(explode('.', $dottedKey), fn (string $segment): bool => $this->isSecretKey($segment));
    }

    /**
     * Masking decision for a dotted path: authoritative secret map first
     * (exact path or any ancestor is a resolved secret), name heuristics
     * second.
     */
    private function isMaskedPath(string $dottedKey): bool
    {
        return $this->inSecretSubtree($dottedKey) || $this->pathIsSecret($dottedKey);
    }

    /** Is this path an authoritative secret, or nested inside one? */
    private function inSecretSubtree(string $dottedKey): bool
    {
        $path = $dottedKey;

        do {
            if (in_array($path, $this->secretPaths, true)) {
                return true;
            }
            $pos = strrpos($path, '.');
            $path = $pos === false ? '' : substr($path, 0, $pos);
        } while ($path !== '');

        return false;
    }

    private function isSecretKey(string $key): bool
    {
        if (TelemetrySanitizer::isSensitiveKey($key)) {
            return true;
        }
        $normalized = strtolower(str_replace(['_', ' '], '-', $key));

        return array_any(
            self::SECRET_KEYS,
            fn (string $needle): bool => $normalized === $needle || str_contains($normalized, $needle),
        );
    }

    /** Mask a value as `****` with the original string length as a hint. */
    private function mask(mixed $value): string
    {
        return is_string($value) ? '****(' . strlen($value) . ')' : '****';
    }

    private function isProduction(): bool
    {
        $env = $this->env ?? new Env();

        return strcasecmp($env->readString('ZEF_ENV'), 'production') === 0;
    }
}
