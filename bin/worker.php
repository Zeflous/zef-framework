<?php

/**
 * ZEF Framework v2.7.0 — RoadRunner HTTP worker entrypoint.
 *
 * RoadRunner PHP bridge (spiral/roadrunner-http + nyholm/psr7) sudah termasuk
 * dalam vendor/ sejak v2.14.6; pemasangan manual tetap didukung:
 *
 *   composer require spiral/roadrunner-http nyholm/psr7
 *
 * .rr.yaml:
 *   server:
 *     command: "php bin/worker.php"
 *   http:
 *     address: 0.0.0.0:8080
 *
 * Run:
 *   rr serve
 */

declare(strict_types=1);

// Composer autoload memuat bridge RoadRunner + PSR-7 + framework (PSR-4 + files
// -> autoload/zef_autoload.php). Fallback zero-composer tetap didukung bila
// vendor/ tidak ada — guard class_exists di bawah akan menuntun pemasangan.
if (is_file(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/../autoload/zef_autoload.php';
}

if (PHP_VERSION_ID < 80400) {
    fwrite(STDERR, "ZEF Framework v" . \Zef\Framework\Foundation\ZefVersion::VERSION . " requires PHP >= 8.4\n");
    exit(1);
}

if (!class_exists(\Spiral\RoadRunner\Http\PSR7Worker::class)) {
    fwrite(STDERR, "RoadRunner bridge not installed.\nRun: composer require spiral/roadrunner-http nyholm/psr7\n");
    exit(1);
}

// Bridge spiral/roadrunner-http v4: HttpWorker::waitRequest() mengembalikan DTO
// Spiral, PSR-7 disediakan oleh PSR7Worker (waitRequest(): ?ServerRequestInterface
// dan respond(ResponseInterface)) — kontrak yang diharapkan RoadRunnerRuntime.
$psr17 = new \Nyholm\Psr7\Factory\Psr17Factory();
$worker = new \Spiral\RoadRunner\Http\PSR7Worker(
    \Spiral\RoadRunner\Worker::create(),
    $psr17,
    $psr17,
    $psr17,
);

$debug  = filter_var(getenv('ZEF_DEBUG') ?: '0', FILTER_VALIDATE_BOOL);
$app    = \Zef\App\Bootstrap::createApp($debug);
$runtime = new \Zef\Framework\Runtime\RoadRunnerRuntime(
    $app,
    new \Zef\Framework\Runtime\RoadRunnerWorkerAdapter($worker),
    maxJobs: (int) (getenv('ZEF_WORKER_MAX_JOBS') ?: 0),
    memoryLimitBytes: (int) (getenv('ZEF_WORKER_MEMORY_LIMIT') ?: 0),
);

exit($runtime->run());
