<?php

declare(strict_types=1);

/*
 * ZEF Framework — v2.10.0 enterprise feature suite (dedicated test file).
 *
 * Covers the Enterprise Feature Pack end to end:
 *   - contextual binding, decoration chain, deferred providers, container events
 *   - route groups/prefixes, compiled route cache, fallback routes
 *   - API version negotiation, sort/filter specs, rotating key ring
 *   - localized validation messages, form request objects, tinker session
 *
 * Run standalone:  bin/zef --self-test=v210
 * Run everything:  bin/zef --self-test
 */

namespace Zef\Test;

use Zef\Framework\Container\BootableProviderInterface;
use Zef\Framework\Container\Container;
use Zef\Framework\Container\DeferrableProviderInterface;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Container\ServiceProviderInterface;
use Zef\Framework\Container\ServiceRegistrarInterface;
use Zef\Framework\Exception\ApiVersionUnsupportedException;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\MethodNotAllowedException;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\RouteConstraintException;
use Zef\Framework\Exception\RouteNotFoundException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Exception\ServiceNotFoundException;
use Zef\Framework\Exception\ServiceResolutionException;
use Zef\Framework\Http\ApiVersion;
use Zef\Framework\Http\ApiVersionNegotiator;
use Zef\Framework\Http\FormRequest;
use Zef\Framework\Http\ServerRequest;
use Zef\Framework\Http\Uri;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Resource\SortSpec;
use Zef\Framework\Router\RouteCache;
use Zef\Framework\Router\Router;
use Zef\Framework\Runtime\TinkerSession;
use Zef\Framework\Security\AesGcmEncryptor;
use Zef\Framework\Security\RotatingKeyRing;
use Zef\Framework\Validation\MessageCatalog;
use Zef\Framework\Validation\ValidationResult;
use Zef\Framework\Validation\ValidationTranslator;
use Zef\Framework\Validation\Validator;

final class V2100EnterpriseSuite
{
    public function __construct(private readonly CliRunner $runner) {}

    /** Entry point invoked by CliRunner::run(). */
    public function run(): void
    {
        $this->v210Contextual();
        $this->v210Decoration();
        $this->v210Providers();
        $this->v210Events();
        $this->v210RouterGroups();
        $this->v210RouteCache();
        $this->v210Fallback();
        $this->v210ApiVersion();
        $this->v210SortFilter();
        $this->v210KeyRing();
        $this->v210I18n();
        $this->v210FormRequest();
        $this->v210Tinker();
    }

    private function ok(bool $cond, string $label): void
    {
        $this->runner->ok($cond, $label);
    }

    private function throws(string $class, callable $fn, string $label): void
    {
        $this->runner->throws($class, $fn, $label);
    }

    // ------------------------------------------------------------------

    private function v210Contextual(): void
    {
        $c = new Container();
        $c->register('gate.stripe', static fn (): string => 'STRIPE', []);
        $c->register('gate.paypal', static fn (): string => 'PAYPAL', []);
        $c->register('gate.default', static fn (): string => 'DEFAULT', []);
        $c->register('svc.billing', static fn ($ctx, $gate): object => (object) ['gate' => $gate], ['gate.default']);
        $c->register('svc.shipping', static fn ($ctx, $gate): object => (object) ['gate' => $gate], ['gate.default']);

        $c->when('svc.billing')->needs('gate.default')->give('gate.stripe');
        $c->validateAndFreeze();

        $this->ok($c->get('svc.billing')->gate === 'STRIPE', 'v210: contextual binding redirects billing to stripe');
        $this->ok($c->get('svc.shipping')->gate === 'DEFAULT', 'v210: other consumers keep the original dependency');

        // Unknown target: graph compile fails with ServiceNotFoundException.
        $c2 = new Container();
        $c2->register('gate.x', static fn (): string => 'X', []);
        $c2->register('svc.two', static fn ($ctx, $d): string => $d, ['gate.x']);
        $c2->when('svc.two')->needs('gate.x')->give('gate.missing');
        $this->throws(ServiceNotFoundException::class, static fn () => $c2->validateAndFreeze(), 'v210: contextual unknown target fails graph compile');

        // Consumer unknown / dep not declared / double binding.
        $c3 = new Container();
        $c3->register('gate.y', static fn (): string => 'Y', []);
        $this->throws(
            InvalidConfigurationException::class,
            function () use ($c3): void {
                $c3->when('nope.consumer')->needs('gate.y')->give('gate.y');
            },
            'v210: contextual unknown consumer rejected',
        );

        $c4 = new Container();
        $c4->register('svc.na', static fn (): string => 'no-deps', []);
        $c4->register('gate.z', static fn (): string => 'Z', []);
        $this->throws(
            InvalidConfigurationException::class,
            function () use ($c4): void {
                $c4->when('svc.na')->needs('gate.z')->give('gate.z');
            },
            'v210: contextual dep not declared by consumer rejected',
        );

        $c5 = new Container();
        $c5->register('svc.d', static fn ($ctx, $d): string => $d, ['gate.z']);
        $c5->register('gate.z', static fn (): string => 'Z', []);
        $c5->when('svc.d')->needs('gate.z')->give('gate.z');
        $this->throws(
            InvalidConfigurationException::class,
            function () use ($c5): void {
                $c5->when('svc.d')->needs('gate.z')->give('gate.z');
            },
            'v210: duplicate contextual binding rejected',
        );

        // Cross-module budget still counts through the rewritten graph: two
        // rewritten deps both now point into modB (budget 1 → second edge fails).
        $c6 = new Container();
        $c6->configurePolicies(1);
        $c6->register('moda.consumer', static fn ($ctx, $x, $y): string => $x . $y, ['dep.a', 'dep.b'], 'modA');
        $c6->register('dep.a', static fn (): string => 'a', [], 'modA');
        $c6->register('dep.b', static fn (): string => 'b', [], 'modA');
        $c6->register('dep.x', static fn (): string => 'x', [], 'modB');
        $c6->register('dep.y', static fn (): string => 'y', [], 'modB');
        $c6->when('moda.consumer')->needs('dep.a')->give('dep.x');
        $c6->when('moda.consumer')->needs('dep.b')->give('dep.y');
        $this->throws(
            ModuleDependencyViolationException::class,
            static fn () => $c6->validateAndFreeze(),
            'v210: cross-module budget enforced through contextual rewrite',
        );

        // Cycles introduced through contextual bindings are still detected.
        $c7 = new Container();
        $c7->register('cyc.a', static fn ($ctx, $d): string => $d, ['cyc.dep'], 'modA');
        $c7->register('cyc.b', static fn ($ctx, $d): string => $d, ['cyc.dep2'], 'modA');
        $c7->register('cyc.dep', static fn (): int => 1, [], 'modA');
        $c7->register('cyc.dep2', static fn (): int => 2, [], 'modA');
        $c7->when('cyc.a')->needs('cyc.dep')->give('cyc.b');
        $c7->when('cyc.b')->needs('cyc.dep2')->give('cyc.a');
        $this->throws(
            ServiceCircularDependencyException::class,
            static fn () => $c7->validateAndFreeze(),
            'v210: cycle through contextual rewrite detected by graph validator',
        );

        $this->ok(count($c->getContextualBindings()) === 1, 'v210: contextual binding introspection');
    }

    private function v210Decoration(): void
    {
        $calls = [];
        $c = new Container();
        $c->register('mail.core', static function () use (&$calls): object {
            $calls[] = 'inner';

            return (object) ['kind' => 'inner'];
        }, []);
        $c->decorate('mail.core', function ($ctx, $inner) use (&$calls) {
            $calls[] = 'a';

            return (object) ['kind' => 'a(' . $inner->kind . ')', 'inner' => $inner];
        });
        $c->decorate('mail.core', function ($ctx, $inner) use (&$calls) {
            $calls[] = 'b';

            return (object) ['kind' => 'b(' . $inner->kind . ')', 'inner' => $inner];
        });
        $c->validateAndFreeze();

        $mail = $c->get('mail.core');
        $this->ok($mail->kind === 'a(b(inner))', 'v210: decorator chain order (first registered = outermost)');
        $this->ok($calls === ['inner', 'b', 'a'], 'v210: decoration invocation order inner→outward');
        $this->ok($c->get('mail.core') === $mail, 'v210: decorated singleton still cached');
        $this->ok($c->has('mail.core') && $c->has('@inner:mail.core:base'), 'v210: inner definition visible under synthetic id');

        // Unknown service rejected at freeze time.
        $c2 = new Container();
        $c2->decorate('ghost.service', static fn ($ctx, $inner) => $inner);
        $this->throws(
            InvalidConfigurationException::class,
            static fn () => $c2->validateAndFreeze(),
            'v210: decorating unknown service rejected',
        );

        // Frozen container rejects late decoration.
        $this->throws(
            \LogicException::class,
            fn () => $c->decorate('mail.core', static fn ($ctx, $inner) => $inner),
            'v210: decorate after freeze rejected',
        );

        // Decoration keeps request-scoped lifetime semantics.
        $c3 = new Container();
        $c3->register('req.ctx', static fn (): object => (object) ['n' => random_int(1, 1 << 20)], [], ServiceLifetime::REQUEST);
        $c3->decorate('req.ctx', static fn ($ctx, $inner) => (object) ['w' => $inner]);
        $c3->validateAndFreeze();
        $scope = $c3->createRequestScope();
        $a = $scope->get('req.ctx');
        $b = $scope->get('req.ctx');
        $this->ok($a === $b && $a->w instanceof \stdClass, 'v210: request-scoped decoration stays per-request shared');
        $scope->close();
    }

    private function v210Providers(): void
    {
        $eager = new class implements ServiceProviderInterface {
            public int $registers = 0;

            #[\Override]
            public function provides(): array
            {
                return ['eager.svc'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                ++$this->registers;
                $container->register('eager.svc', static fn (): string => 'EAGER', []);
            }
        };
        $deferred = new class implements DeferrableProviderInterface {
            public int $registers = 0;
            public int $boots = 0;

            #[\Override]
            public function provides(): array
            {
                return ['lazy.svc', 'lazy.alt'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                ++$this->registers;
                $container->register('lazy.svc', static fn (): string => 'LAZY', []);
                $container->register('lazy.alt', static fn (): string => 'LAZY2', []);
            }
        };
        $bootable = new class implements DeferrableProviderInterface, BootableProviderInterface {
            public int $registers = 0;
            public int $boots = 0;

            #[\Override]
            public function provides(): array
            {
                return ['boot.svc'];
            }

            #[\Override]
            public function register(ServiceRegistrarInterface $container): void
            {
                ++$this->registers;
                $container->register('boot.svc', static fn (): string => 'BOOT', []);
            }

            #[\Override]
            public function boot(ServiceRegistrarInterface $container): void
            {
                ++$this->boots;
            }
        };

        $c = new Container();
        $c->registerProvider($eager);
        $c->registerProvider($deferred);
        $c->registerProvider($bootable);
        // The bootable provider's service is referenced by the graph, so its
        // register() must run during validateAndFreeze() (deferred loading).
        $c->register('host.svc', static fn ($ctx, $b): string => $b, ['boot.svc']);

        $this->ok($eager->registers === 1, 'v210: eager provider registered immediately');
        $this->ok($deferred->registers === 0 && $bootable->registers === 0, 'v210: deferred providers not registered yet');
        $this->ok(!$c->has('lazy.svc'), 'v210: has() stays false for pending deferred provider');

        $c->validateAndFreeze();
        $this->ok($bootable->registers === 1, 'v210: graph-referenced deferred provider auto-registered at freeze');
        $this->ok($c->get('boot.svc') === 'BOOT', 'v210: deferred-registered service resolves after freeze');

        $c->bootProviders();
        $c->bootProviders();
        $this->ok($bootable->boots === 1, 'v210: bootProviders runs once for registered bootables');

        // Direct pre-freeze get() triggers a pending deferred provider lazily.
        $c2 = new Container();
        $c2->registerProvider($deferred);
        $this->ok($deferred->registers === 0, 'v210: pending stays pending before get');
        $this->ok($c2->get('lazy.svc') === 'LAZY', 'v210: pre-freeze get triggers deferred register');
        $this->ok($deferred->registers === 1, 'v210: deferred register ran exactly once');
        $this->ok($c2->get('lazy.alt') === 'LAZY2', 'v210: second provides() id already registered');
        $c2->validateAndFreeze();
        $this->ok($c2->get('lazy.alt') === 'LAZY2', 'v210: resolved deferred services survive freeze');

        // A pending deferred provider requested after freeze fails informatively.
        // ZEF-DEEP-12: PSR-11 contract — has() is false, so get() must surface
        // NotFoundExceptionInterface (ServiceNotFoundException) with the
        // deferred-provider guidance, not a bare LogicException.
        $c3 = new Container();
        $c3->registerProvider($deferred);
        $c3->validateAndFreeze();
        $this->throws(
            ServiceNotFoundException::class,
            static fn (): mixed => $c3->get('lazy.svc'),
            'v210: pending deferred get() after freeze fails with PSR-11 NotFound + guidance',
        );
        $this->ok(count($c->getProviders()) === 3, 'v210: provider introspection in registration order');
    }

    private function v210Events(): void
    {
        $order = [];
        $c = new Container();
        $c->register('evt.svc', static function () use (&$order): string {
            $order[] = 'factory';

            return 'instance';
        }, []);
        $c->onResolving(function (string $id, array $deps) use (&$order): void {
            $order[] = 'resolving:' . $id . ':' . implode(',', $deps);
        });
        $c->onResolved(fn (string $id, mixed $instance): string => 'wrapped(' . $instance . ')');
        $value = $c->get('evt.svc');
        $this->ok($value === 'wrapped(instance)', 'v210: resolved listener return replaces instance');
        $this->ok($order === ['resolving:evt.svc:', 'factory'], 'v210: resolving fires before factory');
        $this->ok($c->get('evt.svc') === $value, 'v210: replaced instance is the cached singleton');

        // Cache hits do not re-fire events.
        $hits = 0;
        $c->onResolving(function () use (&$hits): void {
            ++$hits;
        });
        $c->get('evt.svc');
        $this->ok($hits === 0, 'v210: singleton cache hit does not fire resolving');

        // Listener exceptions are wrapped with service context.
        $c2 = new Container();
        $c2->register('evt.boom', static fn (): string => 'x', []);
        $c2->onResolving(static function (): never {
            throw new \RuntimeException('listener exploded');
        });
        $this->throws(
            ServiceResolutionException::class,
            static fn (): mixed => $c2->get('evt.boom'),
            'v210: resolving listener failure wrapped as ServiceResolutionException',
        );
    }

    private function v210RouterGroups(): void
    {
        $r = new Router();
        $r->add('GET', '/health', 'h.health');
        $r->group(['prefix' => '/api', 'name' => 'api.', 'middleware' => ['auth.token']], function (Router $r): void {
            $r->add('GET', '/users', 'u.index', name: 'users');
            $r->group(['prefix' => '/v2', 'name' => 'v2.', 'middleware' => ['rate.limit']], function (Router $r): void {
                $r->add('GET', '/orders/{id:int}', 'o.show', name: 'orders.show');
            });
            $r->add('POST', '/users', 'u.create', name: 'users.create');
        });

        $this->ok($r->match('GET', '/api/users')['handler'] === 'u.index', 'v210: group prefix applied to pattern');
        $this->ok($r->match('GET', '/api/v2/orders/9')['params']['id'] === '9', 'v210: nested group prefix concatenates');
        $this->ok($r->patternFor('api.v2.orders.show') === '/api/v2/orders/{id:int}', 'v210: nested name prefix concatenates');
        $routes = $r->getRoutes();
        $mw = [];
        foreach ($routes as $route) {
            $mw[$route['pattern']] = $route['middleware'];
        }
        $this->ok($mw['/health'] === [], 'v210: route outside groups has empty middleware');
        $this->ok($mw['/api/users'] === ['auth.token'], 'v210: group middleware attached');
        $this->ok($mw['/api/v2/orders/{id:int}'] === ['auth.token', 'rate.limit'], 'v210: nested middleware merges in order');

        $this->throws(
            \InvalidArgumentException::class,
            function () use ($r): void {
                $r->group(['prefix' => 'api'], static fn (): null => null);
            },
            'v210: group prefix must start with /',
        );
        $this->throws(
            \InvalidArgumentException::class,
            function () use ($r): void {
                // Registering the same effective pattern (group prefix included)
                // must still hit O(1) collision detection.
                $r->add('GET', '/api/users', 'dupe.handler');
            },
            'v210: duplicate route via same effective pattern still collides',
        );
        $this->throws(
            \LogicException::class,
            function () use ($r): void {
                $r->freeze();
                $r->group(['prefix' => '/x'], static fn (): null => null);
            },
            'v210: group after freeze rejected',
        );
    }

    private function v210RouteCache(): void
    {
        $r = new Router();
        $r->addConstraint('sku', '/^[A-Z]{2}\d{4}$/');
        $r->group(['prefix' => '/api', 'name' => 'api.'], function (Router $r): void {
            $r->add('GET', '/items/{code:sku}', 'i.show', priority: 10, name: 'items.show');
            $r->add('GET', '/free', 'i.free', name: 'items.free');
        });
        $r->fallback('err.404');

        $path = sys_get_temp_dir() . '/zef_v210_routes_' . getmypid() . '.php';

        try {
            RouteCache::write($r, $path);
            $this->ok(is_file($path), 'v210: route cache file written');
            $this->ok(str_contains((string) file_get_contents($path), 'return array'), 'v210: cache file is pure PHP data');

            $loaded = RouteCache::load($path);
            $this->ok($loaded->isFrozen(), 'v210: loaded router is frozen & radix-compiled');
            $hit = $loaded->match('GET', '/api/items/AB1234');
            $this->ok($hit['handler'] === 'i.show' && $hit['params']['code'] === 'AB1234', 'v210: custom constraint survives cache round-trip');
            $this->ok($loaded->patternFor('api.items.show') === '/api/items/{code:sku}', 'v210: route names survive cache round-trip');
            $this->ok($loaded->hasFallback() && $loaded->matchOrFallback('GET', '/zzz')['fallback'] === true, 'v210: fallback survives cache round-trip');
            $this->throws(
                RouteConstraintException::class,
                static fn (): array => $loaded->match('GET', '/api/items/ZZ12'),
                'v210: loaded router enforces custom constraints like the original',
            );
        } finally {
            @unlink($path); // nosemgrep: php.lang.security.unlink-use
        }
    }

    private function v210Fallback(): void
    {
        $r = new Router();
        $r->add('GET', '/known', 'h.known');
        $this->ok(!$r->hasFallback(), 'v210: no fallback by default');
        $this->throws(
            RouteNotFoundException::class,
            static fn (): array => $r->matchOrFallback('GET', '/unknown'),
            'v210: matchOrFallback rethrows when no fallback registered',
        );

        $r->fallback('h.notFound');
        $hit = $r->matchOrFallback('GET', '/unknown');
        $this->ok($hit['fallback'] === true && $hit['handler'] === 'h.notFound', 'v210: unmatched path resolves to fallback handler');
        $this->ok($r->matchOrFallback('GET', '/known')['fallback'] === false, 'v210: normal match marked not-fallback');
        $this->throws(
            MethodNotAllowedException::class,
            static fn (): array => $r->matchOrFallback('POST', '/known'),
            'v210: 405 semantics preserved (fallback does not mask)',
        );

        $this->throws(
            \InvalidArgumentException::class,
            static fn () => $r->fallback(''),
            'v210: empty fallback handler rejected',
        );
    }

    private function v210ApiVersion(): void
    {
        $n = new ApiVersionNegotiator(['1', '2', '3'], default: '1');

        $v = $n->negotiate('/v2/users');
        $this->ok($v->version === '2' && $v->source === ApiVersion::SOURCE_PATH, 'v210: path prefix wins and strips');
        [$version, $rest] = $n->splitPathPrefix('/v2/users');
        $this->ok($version === '2' && $rest === '/users', 'v210: splitPathPrefix returns version + rest');
        [$version, $rest] = $n->splitPathPrefix('/v3');
        $this->ok($version === '3' && $rest === '/', 'v210: prefix-only path normalizes to /');

        $this->ok($n->negotiate('/users', '3')->source === ApiVersion::SOURCE_HEADER, 'v210: header used when no path prefix');
        $this->ok($n->negotiate('/users', null, '2')->source === ApiVersion::SOURCE_QUERY, 'v210: query used when no header');
        $this->ok($n->negotiate('/users')->version === '1' && $n->negotiate('/users')->source === ApiVersion::SOURCE_DEFAULT, 'v210: default applied last');
        $this->ok($n->negotiate('/users', '  2 ')->version === '2', 'v210: header whitespace tolerated');

        $unsupported = null;

        try {
            $n->negotiate('/v9/users');
        } catch (ApiVersionUnsupportedException $e) {
            $unsupported = $e;
        }
        $this->ok($unsupported instanceof ApiVersionUnsupportedException && $unsupported->requested === '9'
            && in_array('2', $unsupported->supported, true), 'v210: unsupported path version raises with details');

        $this->throws(
            ApiVersionUnsupportedException::class,
            static fn (): ApiVersion => $n->negotiate('/users', '99'),
            'v210: unsupported header version rejected',
        );
        $oversized = $n->negotiate('/users', str_repeat('x', 300));
        $this->ok($oversized->version === '1' && $oversized->source === ApiVersion::SOURCE_DEFAULT, 'v210: oversized header treated as absent (then default)');

        $this->throws(
            \InvalidArgumentException::class,
            static fn (): ApiVersionNegotiator => new ApiVersionNegotiator(['1'], default: '2'),
            'v210: default must be part of supported list',
        );
    }

    private function v210SortFilter(): void
    {
        $rows = [
            ['name' => 'gamma', 'price' => 30, 'cat' => 'b'],
            ['name' => 'alpha', 'price' => 10, 'cat' => 'a'],
            ['name' => 'beta', 'price' => 20, 'cat' => 'a'],
        ];

        $sort = SortSpec::fromQuery(['sort' => 'price'], ['name', 'price']);
        $this->ok(array_column($sort->applyTo($rows), 'name') === ['alpha', 'beta', 'gamma'], 'v210: sort ascending by whitelisted field');
        $desc = SortSpec::fromQuery(['sort' => '-price,name'], ['name', 'price']);
        $this->ok(array_column($desc->applyTo($rows), 'name') === ['gamma', 'beta', 'alpha'], 'v210: desc prefix handled');
        $this->ok($desc->toQuery() === '-price,name', 'v210: toQuery round-trips');

        $multi = SortSpec::fromQuery(['sort' => 'cat,-price'], ['cat', 'price', 'name']);
        $this->ok(array_column($multi->applyTo($rows), 'name') === ['beta', 'alpha', 'gamma'], 'v210: multi-key sort with per-key direction');

        $unknown = SortSpec::fromQuery(['sort' => '-hack_sql,price'], ['name', 'price']);
        $this->ok(count($unknown->keys()) === 1 && $unknown->keys()[0]->field === 'price', 'v210: non-whitelisted sort field dropped');

        $defaulted = SortSpec::fromQuery([], ['name', 'price'], defaultFields: ['name']);
        $this->ok($defaulted->keys()[0]->field === 'name', 'v210: default keys applied when query empty');

        $filter = FilterSpec::fromQuery(
            ['filter' => ['cat' => 'a', 'price_gte' => 15]],
            ['name', 'price', 'cat'],
        );
        $filtered = $filter->applyTo($rows);
        $this->ok(count($filtered) === 1 && $filtered[0]['name'] === 'beta', 'v210: nested eq + gte combine');

        $flat = FilterSpec::fromQuery(['filter_price_lte' => '20', 'filter_name_in' => 'alpha,beta'], ['name', 'price']);
        $flatRows = $flat->applyTo($rows);
        $this->ok(count($flatRows) === 2 && $flatRows[0]['name'] === 'alpha', 'v210: flat form + in operator');

        $like = FilterSpec::fromQuery(['filter' => ['name_like' => 'AMM']], ['name']);
        $this->ok(count($like->applyTo($rows)) === 1, 'v210: like is case-insensitive substring');

        $bad = FilterSpec::fromQuery(['filter' => ['hack_sql_eq' => 'x', 'price_eq' => str_repeat('9', 400)]], ['price']);
        $this->ok(
            count($bad->conditions()) === 1
            && $bad->conditions()[0]->field === 'price'
            && strlen((string) $bad->conditions()[0]->value) === 256,
            'v210: unknown field rejected and oversized value truncated',
        );

        $neq = FilterSpec::fromQuery(['filter' => ['cat_neq' => 'a']], ['cat']);
        $this->ok(count($neq->applyTo($rows)) === 1 && $neq->applyTo($rows)[0]['cat'] === 'b', 'v210: neq operator');
    }

    private function v210KeyRing(): void
    {
        $old = bin2hex(random_bytes(32));
        $new = bin2hex(random_bytes(32));
        $oldEnc = new AesGcmEncryptor($old);

        $ring = new RotatingKeyRing([$new, $old]);
        $payload = $oldEnc->encrypt('legacy-data');

        $this->ok($ring->keyCount() === 2 && $ring->activeIndex() === 0, 'v210: ring constructed with active index');
        $this->ok($ring->decrypt($payload) === 'legacy-data', 'v210: retired-key payload decrypts via ring probe');
        $this->ok(new AesGcmEncryptor($new)->decrypt($ring->encrypt('fresh-data')) === 'fresh-data', 'v210: encrypt uses the active key');

        $rotated = $ring->withActiveIndex(1);
        $this->ok($rotated->activeIndex() === 1 && $ring->activeIndex() === 0, 'v210: withActiveIndex is immutable');
        $this->ok(new AesGcmEncryptor($old)->decrypt($rotated->encrypt('rolled')) === 'rolled', 'v210: rotated ring encrypts with new active key');

        $this->throws(
            \RuntimeException::class,
            static fn (): string => $ring->decrypt('garbage.payload'),
            'v210: malformed payload fails all keys',
        );

        $this->throws(
            \InvalidArgumentException::class,
            static fn (): RotatingKeyRing => new RotatingKeyRing([]),
            'v210: empty ring rejected',
        );
        $this->throws(
            \InvalidArgumentException::class,
            static fn (): RotatingKeyRing => new RotatingKeyRing([$old, 'weak-key']),
            'v210: invalid key material rejected eagerly',
        );
        $this->throws(
            \InvalidArgumentException::class,
            static fn (): RotatingKeyRing => new RotatingKeyRing([$old], activeIndex: 5),
            'v210: active index out of range rejected',
        );
    }

    private function v210I18n(): void
    {
        $v = new Validator();
        $v->field('email')->required();
        $result = $v->validate(['email' => null]);

        $en = ValidationTranslator::class;
        $catalog = MessageCatalog::defaultEnglish()
            ->with('id', ['required' => '{{label}} wajib diisi.'])
            ->with('id_ID', ['required' => '{{label}} wajib diisi untuk wilayah ID.'])
        ;

        $translator = new ValidationTranslator($catalog);
        $idId = $translator->translate($result, 'id_ID', ['email' => 'Alamat Email']);
        $this->ok($idId->messages()[0] === 'Alamat Email wajib diisi untuk wilayah ID.', 'v210: exact locale wins with {{label}} interpolation');

        $id = $translator->translate($result, 'id', ['email' => 'Alamat Email']);
        $this->ok($id->messages()[0] === 'Alamat Email wajib diisi.', 'v210: base locale fallback id_ID→id');

        $noLabel = $translator->translate($result, 'id');
        $this->ok($noLabel->messages()[0] === 'email wajib diisi.', 'v210: missing label falls back to raw field name');

        $en = $translator->translate($result, 'fr');
        $this->ok(str_contains($en->messages()[0], 'is required'), 'v210: wildcard fallback for unknown locale');

        $custom = new Validator();
        $custom->field('age')->required('tulis umur!');
        // No template anywhere (empty catalog) → original message preserved.
        $kept = new ValidationTranslator(MessageCatalog::empty())->translate($custom->validate([]), 'id');
        $this->ok($kept->messages()[0] === "Field 'age' tulis umur!", 'v210: unknown rule keeps original message');

        $okResult = new ValidationResult([], ['x' => 1]);
        $this->ok($translator->translate($okResult, 'id') === $okResult, 'v210: valid result passes through untouched');

        $this->throws(
            \InvalidArgumentException::class,
            static fn (): MessageCatalog => $catalog->with('bad locale!', ['required' => 'x']),
            'v210: invalid locale rejected',
        );
    }

    private function v210FormRequest(): void
    {
        $validator = new Validator();
        $validator->field('email')->required()->email()->maxLength(254);
        $validator->field('age')->typeInt()->min(0)->max(130)->nullable();
        $validator->field('q')->nullable();

        $request = new ServerRequest(
            'POST',
            new Uri('http://test.local/subscribe?q=hello', ['test.local']),
            [],
            [],
            ['q' => 'hello', 'extra' => 'untouched'],
            [],
            ['email' => 'user@example.test', 'age' => '30'],
        );
        $form = FormRequest::fromServerRequest($validator, $request);
        $this->ok($form->isValid(), 'v210: valid merged payload passes');
        $validated = $form->validated();
        $this->ok($validated === ['email' => 'user@example.test', 'age' => '30', 'q' => 'hello'], 'v210: validated() returns declared fields only');

        $bad = FormRequest::fromServerRequest($validator, new ServerRequest(
            'POST',
            new Uri('http://test.local/subscribe', ['test.local']),
            [],
            [],
            [],
            [],
            ['email' => 'not-an-email', 'age' => '999'],
        ));
        $this->ok(!$bad->isValid() && count($bad->errors()) >= 2, 'v210: invalid payload collects errors');
        $this->ok($bad->errors()[0]['field'] === 'email' && $bad->errors()[0]['rule'] !== '', 'v210: error array shape field/message/rule');

        $queryOnly = FormRequest::fromArray($validator, ['email' => 'a@b.test', 'age' => null]);
        $this->ok($queryOnly->isValid() && $queryOnly->validated()['age'] === null, 'v210: nullable field stays null in validated()');
    }

    private function v210Tinker(): void
    {
        $session = new TinkerSession(['x' => 2]);
        $outputs = $session->run([
            '# comment stays silent',
            '',
            '$x + 40',
            '$y = $x * 2',
            '$y',
            'this is not php !',
            'exit',
            '$x + 1',
        ]);
        $this->ok(count($outputs) === 5, 'v210: skips blanks/comments and stops at exit');
        $this->ok($outputs[0] === '42', 'v210: expression result exported');
        $this->ok($outputs[1] === '4', 'v210: assignment returns value');
        $this->ok($outputs[2] === '4', 'v210: variable state persists across lines');
        $this->ok(str_starts_with((string) $outputs[3], '[error] '), 'v210: parse error contained per line');
        $this->ok($outputs[4] === 'bye.', 'v210: exit keyword prints bye');

        $one = new TinkerSession();
        $this->ok($one->evaluate('"slice-" . "test"') === "'slice-test'", 'v210: one-shot evaluate wraps in return');
        $this->ok($one->evaluate('strlen(str_repeat("a", 5000)) === 5000') === 'true', 'v210: boolean result exported');
    }
}
