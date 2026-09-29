<?php

declare(strict_types=1);

/*
 * ZEF Framework — Application layer (in-process orchestration)
 * Added in the v2.8.0 roadmap continuation (additive, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Psr\Container\ContainerInterface;
use Zef\Framework\Exception\InvalidConfigurationException;
use Zef\Framework\Exception\ServiceNotFoundException;

/**
 * Resolves services by tag.
 *
 * Service definitions already accept a `tags` list (see ServiceDefinition);
 * this locator is the missing read side: it indexes the registry by tag and
 * resolves tagged services through the container on demand.
 *
 * Tag syntax: `[A-Za-z0-9._-]{1,128}` (same discipline as job header names).
 * Ordering of `idsFor()` follows service registration order, which is stable
 * for a given set of providers.
 *
 * Usage in a ConfigProvider:
 *   'services' => [
 *       'acme.exporter.csv' => ['factory' => ..., 'tags' => ['acme.exporter']],
 *   ],
 * Resolution:
 *   $locator->resolveAll('acme.exporter');
 */
final class TaggedServiceLocator
{
    /**
     * @var array<string,list<string>>
     */
    private array $index = [];
    private bool $indexed = false;

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ServiceRegistryView $view,
    ) {}

    public function hasTag(string $tag): bool
    {
        return $this->idsFor($tag) !== [];
    }

    /** @return list<string> */
    public function idsFor(string $tag): array
    {
        $tag = $this->normalize($tag);
        if (!$this->indexed) {
            $this->buildIndex();
        }

        return $this->index[$tag] ?? [];
    }

    /**
     * Resolve every service carrying the tag. Service failures propagate
     * unchanged so resolution problems surface loudly (fail-fast policy).
     *
     * @return list<mixed>
     */
    public function resolveAll(string $tag): array
    {
        $resolved = [];
        foreach ($this->idsFor($tag) as $id) {
            $resolved[] = $this->container->get($id);
        }

        return $resolved;
    }

    /**
     * Resolve exactly one tagged service; null when the tag is unused.
     * Multiple services under one tag is a configuration error — use
     * resolveAll() when multiplicity is expected.
     *
     * @throws InvalidConfigurationException
     */
    public function resolveOne(string $tag): mixed
    {
        $ids = $this->idsFor($tag);
        if ($ids === []) {
            return null;
        }
        if (count($ids) > 1) {
            throw new InvalidConfigurationException(
                "Tag '{$this->normalize($tag)}' is carried by " . count($ids)
                . ' services; use resolveAll() or make the tag unique.',
            );
        }

        return $this->container->get($ids[0]);
    }

    /**
     * Require exactly one tagged service; throws when absent.
     *
     * @throws ServiceNotFoundException
     */
    public function requireOne(string $tag): mixed
    {
        $service = $this->resolveOne($tag);
        if ($service === null) {
            throw new ServiceNotFoundException($this->normalize($tag));
        }

        return $service;
    }

    private function buildIndex(): void
    {
        foreach ($this->view->definitions() as $id => $definition) {
            foreach ($definition->tags as $tag) {
                // @infection-ignore-all LogicalOr — ekuivalen: ServiceDefinition sudah memvalidasi tag non-kosong
                if (!is_string($tag) || $tag === '') {
                    continue;
                }
                $normalized = $this->normalize($tag);
                $this->index[$normalized] ??= [];
                if (!in_array($id, $this->index[$normalized], true)) {
                    $this->index[$normalized][] = $id;
                }
            }
        }
        // @infection-ignore-all TrueValue — ekuivalen: re-index membangun ulang map identik
        $this->indexed = true;
    }

    private function normalize(string $tag): string
    {
        $tag = trim($tag);
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $tag) !== 1) {
            throw new \InvalidArgumentException("Invalid service tag '{$tag}': expected [A-Za-z0-9._-]{1,128}.");
        }

        return $tag;
    }
}
