<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.16.0 — Infrastructure layer (outbound adapters).
 * ZEF Maker: scaffold an app-level PSR-15 middleware.
 * v2.16.0 bugfix: files are written to src/Middleware/ (the composer
 * PSR-4 target of Zef\Middleware\) instead of the legacy app/Middleware/
 * directory that the autoloader does not map.
 */

namespace Zef\Framework\Console\Generator;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Console\GeneratorInterface;
use Zef\Framework\Console\NamingRules;
use Zef\Framework\Console\ScaffoldWriter;

final readonly class MiddlewareGenerator implements GeneratorInterface
{
    public function __construct(
        private string $root,
        private ConsoleIO $io,
        private ScaffoldWriter $writer,
    ) {}

    public function generate(?string $rawName, array $argv = []): int
    {
        $name = NamingRules::className($rawName);
        $snake = NamingRules::snake($name);
        $file = "{$this->root}/src/Middleware/{$name}Middleware.php";
        $this->writer->writeFile($file, <<<PHP
            <?php

            declare(strict_types=1);

            /*
             * ZEF Framework — middleware scaffolded by bin/zef make:middleware.
             */

            namespace Zef\\Middleware;

            use Psr\\Http\\Message\\ResponseInterface;
            use Psr\\Http\\Message\\ServerRequestInterface;
            use Psr\\Http\\Server\\MiddlewareInterface;
            use Psr\\Http\\Server\\RequestHandlerInterface;

            final class {$name}Middleware implements MiddlewareInterface
            {
                #[\\Override]
                public function process(ServerRequestInterface \$request, RequestHandlerInterface \$handler): ResponseInterface
                {
                    // Before: inspect or enrich the request.
                    \$response = \$handler->handle(\$request);
                    // After: inspect or enrich the response.
                    return \$response;
                }
            }

            PHP);
        $this->io->out(<<<TXT

            Next steps — register it in your provider config, then add it to the stack:

              'services' => [
                  'middleware.{$snake}' => [
                      'factory' => static fn(): {$name}Middleware => new {$name}Middleware(),
                      'deps'    => [],
                  ],
              ],
              'middleware.stack' => [
                  ...,
                  ['service' => 'middleware.{$snake}', 'priority' => 500],
              ],

            TXT);

        return 0;
    }
}
