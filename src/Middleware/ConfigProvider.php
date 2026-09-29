<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Demo application
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Zef\Framework\Config\ConfigProviderInterface;
use Zef\Framework\Foundation\Env;
use Zef\Framework\Foundation\EnvInterface;
use Zef\Framework\Security\RateLimiterInterface;
use Zef\Framework\Security\RateLimitMiddleware;
use Zef\Framework\Security\SecurityPolicy;
use Zef\Framework\Security\SecurityRuntimeMiddleware;

final readonly class ConfigProvider implements ConfigProviderInterface
{
    public function __construct(
        private bool $devMode = false,
        private EnvInterface $env = new Env(),
    ) {}

    #[\Override]
    public function getModuleName(): string
    {
        return 'middleware';
    }

    #[\Override]
    public function getConfig(): array
    {
        $devMode = $this->devMode;
        $env = $this->env;

        return [
            'services' => [
                'middleware.error' => [
                    'factory' => static fn (ContainerInterface $c): GlobalErrorHandler => new GlobalErrorHandler(
                        $c->get(LoggerInterface::class),
                        new ErrorResponseFactory($devMode),
                    ),
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.timing' => [
                    'factory' => static fn (): TimingMiddleware => new TimingMiddleware(),
                    'deps' => [],
                ],
                'middleware.cors' => [
                    'factory' => $this->buildCors(...),
                    'deps' => [],
                ],
                'middleware.security' => [
                    'factory' => static fn (): SecurityHeadersMiddleware => new SecurityHeadersMiddleware([
                        'hsts' => $env->readBool('ZEF_SECURITY_HSTS'),
                        'csp' => $env->readBool('ZEF_SECURITY_CSP'),
                    ]),
                    'deps' => [],
                ],
                'middleware.security.runtime' => [
                    'factory' => static function (ContainerInterface $c) use ($env): SecurityRuntimeMiddleware {
                        $logger = null;

                        try {
                            $logger = $c->get(LoggerInterface::class);
                        } catch (\Throwable) {
                            // Optional dependency: when the container cannot
                            // resolve a PSR-3 sink the security runtime runs
                            // with logging disabled — protection stays on.
                        }
                        $policy = SecurityPolicy::fromEnvironment($logger, $env);
                        $rateLimiter = self::buildRateLimiter($policy, $logger, $env);

                        // v2.31.0 (Regresi I-5 / issue #173): the swallowed
                        // limiter failures are logged through the same PSR-3
                        // sink the policy already uses.
                        return new SecurityRuntimeMiddleware(
                            $policy,
                            $rateLimiter,
                            logger: $logger instanceof LoggerInterface ? $logger : null,
                        );
                    },
                    'deps' => [LoggerInterface::class],
                ],
                'middleware.security.rate_limit' => [
                    'factory' => static function (?ContainerInterface $c = null) use ($env): RateLimitMiddleware {
                        return SecurityRateLimitWiring::rateLimitMiddleware($env, $c);
                    },
                    'deps' => [],
                ],
            ],
            'stack' => $this->buildStack(),
        ];
    }

    /**
     * The tiered rate-limit middleware joins the stack (right after the
     * global security runtime middleware) ONLY when tiers are configured —
     * an unconfigured tier middleware would be inert, and registering it
     * anyway would suggest protection that is not there.
     *
     * @return list<string>
     */
    private function buildStack(): array
    {
        $stack = [
            'middleware.error',
            'middleware.security.runtime',
            'middleware.security',
            'middleware.timing',
            'middleware.cors',
        ];
        if (trim($this->env->readString('ZEF_SECURITY_RATE_LIMIT_TIERS')) !== '') {
            array_splice($stack, 2, 0, ['middleware.security.rate_limit']);
        }

        return $stack;
    }

    private function buildCors(): CorsMiddleware
    {
        if ($this->env->readBool('ZEF_CORS_ORIGIN_ANY')) {
            return new CorsMiddleware(['*']);
        }
        $origins = $this->env->readCsv('ZEF_CORS_ORIGIN');

        return new CorsMiddleware($origins);
    }

    /**
     * Bug fix #17: logger passed to buildRateLimiter for fallback warning.
     * The store selection and Redis connection live in
     * {@see SecurityRateLimitWiring} (class-coupling budget).
     */
    private static function buildRateLimiter(
        SecurityPolicy $policy,
        ?LoggerInterface $logger = null,
        ?EnvInterface $env = null,
    ): RateLimiterInterface {
        return SecurityRateLimitWiring::limiterFor($policy, $logger, $env);
    }
}
