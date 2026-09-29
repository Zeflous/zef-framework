<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold a CQRS command + its handler inside an existing
 * module (Command/ subdirectory, wired for CommandBus::register()).
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class CommandGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName, 'command name');
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

        $modulePascal = basename($moduleDir);
        $ns = "Zef\\Module\\{$modulePascal}\\Command";
        $snake = NamingRules::snake($name);
        $this->writer->writeFiles([
            "{$moduleDir}/Command/{$name}Command.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — CQRS command '{$module}.command.{$snake}' (scaffolded by bin/zef make:command).
                 */

                namespace {$ns};

                /**
                 * Immutable intent object. Everything a handler needs must travel through
                 * public readonly properties — commands are messages, not services.
                 */
                final class {$name}Command
                {
                    public function __construct(
                        public readonly string \$id,
                    ) {
                    }
                }

                PHP,
            "{$moduleDir}/Command/{$name}CommandHandler.php" => <<<PHP
                <?php

                declare(strict_types=1);

                /*
                 * ZEF Framework — CQRS command handler (scaffolded by bin/zef make:command).
                 */

                namespace {$ns};

                use Zef\\Framework\\CQRS\\CommandHandlerInterface;
                use Zef\\Framework\\CQRS\\CqrsContext;

                final class {$name}CommandHandler implements CommandHandlerInterface
                {
                    #[\\Override]
                    public function __invoke(object \$command, CqrsContext \$context): mixed
                    {
                        // The scaffold intentionally stops here: the handler is a
                        // compile-checked placeholder, not a finished use case.
                        // This TODO is a PLANNED extension point, not unpaid debt —
                        // it is surfaced by `bin/zef make:command` in generated
                        // projects and is not a marker of unfinished work here.
                        // TODO: perform the side-effect and return a result (or null).
                        return null;
                    }
                }

                PHP,
        ]);
        $this->io->out(<<<TXT

            Next steps — wire it into modules/{$modulePascal}/ConfigProvider.php and the bus:

              // the handler lives in the Command\\ sub-namespace — import it:
              use Zef\\Module\\{$modulePascal}\\Command\\{$name}CommandHandler;

              'services' => [
                  ...
                  '{$module}.command.{$snake}' => [
                      'factory' => static fn(): {$name}CommandHandler => new {$name}CommandHandler(),
                      'deps'    => [],
                  ],
              ],

              // during boot, resolving the handler from the container
              // (the bus stays mutable until freeze()) — best from a Module
              // register() hook, see docs/TUTORIAL-CQRS-101.md:
              \$bus->register({$name}Command::class, \$container->get('{$module}.command.{$snake}'));

              // dispatch anywhere:
              \$app->getCommandBus()->dispatch(new {$name}Command('id-1'));

            TXT);

        return 0;
    }
}
