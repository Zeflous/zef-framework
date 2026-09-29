<?php

declare(strict_types=1);

/*
 * ZEF Framework — kernel composition root: application configuration state.
 * Extracted from Application during the sonar-zero campaign
 * (behavior-preserving move; no public API change).
 */

namespace Zef\Framework\Kernel;

use Zef\Framework\Application;
use Zef\Framework\Config\Config;
use Zef\Framework\Config\ConfigLoader;
use Zef\Framework\Config\ConfigMigrator;
use Zef\Framework\Config\ConfigSchema;
use Zef\Framework\Config\ConfigSourceInterface;
use Zef\Framework\Config\SecretsProviderInterface;

/**
 * @internal
 *
 * Mutable pre-boot configuration state of {@see Application}:
 * the registered config sources, secrets provider, schema and migrator,
 * plus the memoized loaded {@see Config} bag.
 *
 * All mutation happens before boot — Application guards the boot flag at its
 * public API — so the state is frozen in practice once the bag is loaded.
 */
final class ApplicationConfigState
{
    /** @var list<ConfigSourceInterface> */
    private array $sources = [];

    private ?SecretsProviderInterface $secretsProvider = null;
    private ?ConfigSchema $schema = null;
    private ?ConfigMigrator $migrator = null;
    private ?int $sourceSchemaVersion = null;
    private ?Config $appConfig = null;

    public function addSource(ConfigSourceInterface $source): void
    {
        $this->sources[] = $source;
    }

    public function setSecretsProvider(SecretsProviderInterface $secrets): void
    {
        $this->secretsProvider = $secrets;
    }

    public function setSchema(ConfigSchema $schema): void
    {
        $this->schema = $schema;
    }

    public function setMigrator(ConfigMigrator $migrator, ?int $sourceSchemaVersion): void
    {
        $this->migrator = $migrator;
        $this->sourceSchemaVersion = $sourceSchemaVersion;
    }

    /**
     * The validated, immutable application configuration bag (memoized:
     * the sources are read and merged exactly once).
     */
    public function config(): Config
    {
        $this->appConfig ??= new ConfigLoader(
            $this->sources,
            $this->secretsProvider,
            $this->schema,
            $this->migrator,
            $this->sourceSchemaVersion,
        )->load();

        return $this->appConfig;
    }
}
