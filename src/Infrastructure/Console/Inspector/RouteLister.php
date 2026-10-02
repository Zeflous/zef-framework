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
            $this->io->out(sprintf('%-8s %-34s %-20s %-30s %-9s %-6s %-22s %s', ...$this->row($route)));
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
        foreach ($this->lines($exported) as $line) {
            $this->io->out($line);
        }

        return 0;
    }

    /**
     * @param array<string,mixed> $route
     *
     * @return array{0:string,1:string,2:string,3:string,4:string,5:int,6:string,7:string}
     */
    private function row(array $route): array
    {
        return [
            $this->cell($route, 'method'),
            $this->cell($route, 'pattern'),
            $this->cell($route, 'name'),
            $this->cell($route, 'handler'),
            $this->cell($route, 'module'),
            is_int($route['priority'] ?? null) ? $route['priority'] : 0,
            $this->middlewareCell($route['middleware'] ?? null),
            $this->hostCell($route['host'] ?? null),
        ];
    }

    /**
     * @param array<string,mixed> $route
     */
    private function cell(array $route, string $key): string
    {
        $value = $route[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : '-';
    }

    private function middlewareCell(mixed $middleware): string
    {
        if (!is_array($middleware)) {
            return '-';
        }
        $ids = [];
        foreach ($middleware as $entry) {
            if (is_string($entry) && $entry !== '') {
                $ids[] = $entry;
            }
        }

        return $ids === [] ? '-' : implode(',', $ids);
    }

    private function hostCell(mixed $host): string
    {
        return is_string($host) && $host !== '' ? $host : '-';
    }

    /**
     * @param list<array<string,mixed>> $exported
     *
     * @return list<string>
     */
    private function lines(array $exported): array
    {
        $encoded = json_encode($exported, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $lines = is_string($encoded) ? preg_split('/\R/', $encoded) : false;

        return $lines === false ? [] : $lines;
    }
}
