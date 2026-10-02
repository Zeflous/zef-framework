<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.36.0 — Infrastructure layer (outbound adapters).
 * ZEF Inspector: `bin/zef route:list` — prints every registered route,
 * either as a fixed-width text table or (with --json) as a machine-readable
 * JSON array. v2.36.0 adds the MIDDLEWARE and HOST columns. Output goes
 * through the ConsoleIO port so tests can assert it without spawning a
 * subprocess.
 */

namespace Zef\Framework\Console\Inspector;

use Zef\Framework\Console\ConsoleIO;

final readonly class RouteLister
{
    public function __construct(private ConsoleIO $io) {}

    /**
     * @param list<array<string,mixed>> $routes
     */
    public function run(array $routes, bool $json = false): int
    {
        if ($json) {
            return $this->emitJson($routes);
        }

        $this->io->out(sprintf(
            '%-8s %-34s %-20s %-30s %-9s %-6s %-22s %s',
            'METHOD',
            'PATH',
            'NAME',
            'HANDLER',
            'MODULE',
            'PRIO',
            'MIDDLEWARE',
            'HOST',
        ));
        foreach ($routes as $route) {
            $middleware = is_array($route['middleware'] ?? null) ? $route['middleware'] : [];
            $middlewareCell = '-';
            if ($middleware !== []) {
                $ids = [];
                foreach ($middleware as $entry) {
                    if (is_string($entry) && $entry !== '') {
                        $ids[] = $entry;
                    }
                }
                $middlewareCell = $ids === [] ? '-' : implode(',', $ids);
            }
            $name = $route['name'] ?? null;
            $module = $route['module'] ?? null;
            $priority = $route['priority'] ?? null;
            $host = $route['host'] ?? null;
            $this->io->out(sprintf(
                '%-8s %-34s %-20s %-30s %-9s %-6d %-22s %s',
                is_string($route['method'] ?? null) ? $route['method'] : '',
                is_string($route['pattern'] ?? null) ? $route['pattern'] : '',
                is_string($name) ? $name : '-',
                is_string($route['handler'] ?? null) ? $route['handler'] : '',
                is_string($module) ? $module : '-',
                is_int($priority) ? $priority : 0,
                $middlewareCell,
                is_string($host) && $host !== '' ? $host : '-',
            ));
        }
        $this->io->out('');
        $this->io->out(sprintf('%d route(s)', count($routes)));

        return 0;
    }

    /**
     * @param list<array<string,mixed>> $routes
     */
    private function emitJson(array $routes): int
    {
        $exported = [];
        foreach ($routes as $route) {
            $exported[] = [
                'method' => $route['method'] ?? null,
                'path' => $route['pattern'] ?? null,
                'name' => $route['name'] ?? null,
                'handler' => $route['handler'] ?? null,
                'module' => $route['module'] ?? null,
                'priority' => $route['priority'] ?? null,
                'middleware' => is_array($route['middleware'] ?? null) ? $route['middleware'] : [],
                'host' => is_string($route['host'] ?? null) ? $route['host'] : '',
                'bindings' => is_array($route['bindings'] ?? null) ? $route['bindings'] : [],
                'accepts' => is_array($route['accepts'] ?? null) ? $route['accepts'] : [],
            ];
        }
        $encoded = json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines = is_string($encoded) ? preg_split('/\R/', $encoded) : false;
        if ($lines === false) {
            return 1;
        }
        foreach ($lines as $line) {
            $this->io->out($line);
        }

        return 0;
    }
}
