<?php

/**
 * ZEF Framework — Doctum API documentation configuration (composer docs).
 *
 *   composer docs
 *
 * Renders the public API of the four hexagonal layers into build/api.
 *
 * Scope: the source set is the whole `src/` tree rather than a hand-written list
 * of layer directories. The previous hand-list named src/Domain,
 * src/Application, src/Infrastructure and src/Adapters only, so src/Middleware/
 * (namespace Zef\Middleware, 7 classes) and src/Bootstrap.php (namespace
 * Zef\App) were never rendered into the API reference — and every future
 * top-level directory would have gone silently undocumented the same way.
 *
 * `notPath('Compat')` keeps the compatibility shim tree excluded exactly as
 * before. It is a path filter, not a namespace filter, so it matches a path
 * segment named `Compat` at any depth.
 */

declare(strict_types=1);

use Doctum\Doctum;
use Doctum\RemoteRepository\GitHubRemoteRepository;
use Symfony\Component\Finder\Finder;

$iterator = Finder::create()
    ->in(__DIR__ . '/src')
    ->name('*.php')
    ->notPath('Compat');

return new Doctum($iterator, [
    'title'            => 'ZEF Framework API',
    'build_dir'        => __DIR__ . '/build/api',
    'cache_dir'        => __DIR__ . '/build/api-cache',
    'source_dir'       => __DIR__ . '/src/',
    'remote_repository' => new GitHubRemoteRepository('Zeflous/zef-framework', __DIR__),
    'default_opened_level' => 2,
]);
