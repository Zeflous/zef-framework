<?php

declare(strict_types=1);

// ZEF Framework v2.20.0 — Adapters layer (OpenAPI documentation module)

namespace Zef\Framework\OpenApi\Console;

use Zef\Framework\Console\ConsoleIO;
use Zef\Framework\Foundation\ZefVersion;
use Zef\Framework\OpenApi\Info;
use Zef\Framework\OpenApi\JsonSpecificationSerializer;
use Zef\Framework\OpenApi\OpenApiVersion;
use Zef\Framework\OpenApi\PostmanCollectionExporter;
use Zef\Framework\OpenApi\RouteSpecExtractor;
use Zef\Framework\OpenApi\Server;
use Zef\Framework\OpenApi\SpecificationException;
use Zef\Framework\OpenApi\YamlSpecificationSerializer;

/**
 * bin/zef openapi:generate — assemble the OpenAPI document from the
 * booted application's route table and write it out as JSON/YAML.
 *
 * Options:
 *   --format=json|yaml   output format (default json)
 *   --output=<path>      destination file (default openapi.<format>)
 *   --pretty             pretty-print JSON (default on; --no-pretty to disable)
 *   --postman=<path>     additionally write a Postman Collection v2.1 JSON
 *   --base-url=<url>     optional first server entry
 */
final class GenerateSpecCommand
{
    /**
     * @param list<array<string, mixed>> $routes Router::getRoutes() shaped arrays
     * @param null|\Closure(string): ?class-string $classResolver
     * @param array<string, mixed> $args
     */
    public function run(ConsoleIO $io, array $routes, ?\Closure $classResolver = null, array $args = []): int
    {
        $format = isset($args['format']) && is_string($args['format']) ? strtolower($args['format']) : 'json';
        if (!in_array($format, ['json', 'yaml'], true)) {
            $io->err("Unsupported --format '{$format}' (expected json or yaml).");

            return 1;
        }

        $info = new Info(
            title: 'ZEF Framework API',
            version: ZefVersion::VERSION,
            description: 'OpenAPI document assembled from the ZEF route table (bin/zef openapi:generate).',
        );
        $servers = [];
        if (isset($args['base-url']) && is_string($args['base-url']) && $args['base-url'] !== '') {
            $servers[] = new Server($args['base-url']);
        }

        try {
            $extractor = new RouteSpecExtractor($info, $classResolver, OpenApiVersion::V3_1_0);
            $builder = $extractor->extract($routes);
            foreach ($servers as $server) {
                $builder->addServer($server);
            }
            $spec = $builder->build();

            $output = isset($args['output']) && is_string($args['output']) && $args['output'] !== ''
                ? $args['output']
                : 'openapi.' . $format;
            $content = $format === 'yaml'
                ? new YamlSpecificationSerializer()->serialize($spec)
                : new JsonSpecificationSerializer()->serialize($spec, $this->flag($args, 'pretty', true));
            $this->writeFile($output, $content);
            $io->out("OpenAPI specification written: {$output} (" . strlen($content) . ' bytes)');

            if (isset($args['postman']) && is_string($args['postman']) && $args['postman'] !== '') {
                $collection = new PostmanCollectionExporter()->export($spec);
                $postmanJson = new JsonSpecificationSerializer()->serialize($collection, true);
                $this->writeFile($args['postman'], $postmanJson);
                $io->out("Postman collection written: {$args['postman']} (" . strlen($postmanJson) . ' bytes)');
            }
        } catch (SpecificationException $exception) {
            $io->err('openapi:generate failed: ' . $exception->getMessage());

            return 1;
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function flag(array $args, string $name, bool $default): bool
    {
        if (!array_key_exists($name, $args)) {
            return $default;
        }
        $value = $args[$name];

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if ($directory !== '' && !is_dir($directory)) {
            throw new SpecificationException("Output directory '{$directory}' does not exist.");
        }
        // Guard the writable surface up front (php:S2002: no '@' suppression):
        // the return-value check below stays as the race backstop (disk full,
        // file removed between the guard and the write).
        $writableTarget = match (true) {
            is_file($path) => $path,
            $directory === '' => '.',
            default => $directory,
        };
        if (!is_writable($writableTarget)) {
            throw new SpecificationException("Cannot write specification to '{$path}'.");
        }
        if (file_put_contents($path, $content) === false) {
            throw new SpecificationException("Cannot write specification to '{$path}'.");
        }
    }
}
