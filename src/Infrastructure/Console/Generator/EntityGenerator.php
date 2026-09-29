<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a Domain entity inside an existing module
 * (Domain/ subdirectory) with identity semantics built in.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class EntityGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'entity name');
        $module = 'core';
        foreach ($argv as $arg) {
            if (str_starts_with((string) $arg, '--module=')) {
                $module = NamingRules::moduleName(substr((string) $arg, 9));
            }
        }

        $moduleDir = HandlerGenerator::resolveModuleDir($this->root, $module);
        if ($moduleDir === null) {
            $this->io->err("Module '{$module}' does not exist (modules/{$module}). Create it first with make:module.");

            return 1;
        }

        $ns = 'Zef\Module\\' . basename($moduleDir) . '\Domain';
        $this->writer->writeFile("{$moduleDir}/Domain/{$name}.php", <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — entity (scaffolded by bin/zef make:entity).
             */

            namespace {$ns};

            final class {$name}
            {
                private \\DateTimeImmutable \$createdAt;

                public function __construct(
                    private readonly string \$id,
                    ?\\DateTimeImmutable \$createdAt = null,
                ) {
                    \$this->createdAt = \$createdAt ?? new \\DateTimeImmutable();
                }

                public function id(): string
                {
                    return \$this->id;
                }

                public function createdAt(): \\DateTimeImmutable
                {
                    return \$this->createdAt;
                }

                /** Entities are identified by their identity, never by their fields. */
                public function equals(self \$other): bool
                {
                    return \$this->id === \$other->id;
                }
            }

            PHP);
        $this->io->out(<<<'TXT'

            Next steps:
              - Entities carry behaviour. Add state-changing methods (recordX(), rename())
                instead of mutating properties from the outside.
              - Persist through a repository port defined in the module, implemented in
                src/Infrastructure or the module itself.

            TXT);

        return 0;
    }
}
