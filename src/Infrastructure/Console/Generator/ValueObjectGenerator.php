<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a final readonly value object inside an existing
 * module (Domain/ subdirectory) with validation + equality built in.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class ValueObjectGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'value object name');
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
             * ZEF Framework — value object (scaffolded by bin/zef make:valueobject).
             */

            namespace {$ns};

            final readonly class {$name}
            {
                public function __construct(
                    private string \$value,
                ) {
                    if (\\trim(\$value) === '') {
                        throw new \\InvalidArgumentException('{$name} value must not be empty.');
                    }
                }

                public function value(): string
                {
                    return \$this->value;
                }

                /** Value objects are equal when all their fields are equal. */
                public function equals(self \$other): bool
                {
                    return \$this->value === \$other->value;
                }

                public function __toString(): string
                {
                    return \$this->value;
                }
            }

            PHP);
        $this->io->out(<<<'TXT'

            Next steps:
              - Replace the generic `$value` field with the domain's real attributes and
                move every validation rule into the constructor — an invalid instance
                must be unrepresentable.
              - Value objects are immutable: model state changes as `withX()` clones.

            TXT);

        return 0;
    }
}
