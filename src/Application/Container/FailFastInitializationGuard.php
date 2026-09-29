<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Application layer (in-process orchestration)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Container;

use Zef\Framework\Exception\ConcurrentServiceInitializationException;

final class FailFastInitializationGuard implements InitializationGuard
{
    /**
     * @var array<string,bool>
     */
    private array $active = [];

    #[\Override]
    public function synchronized(string $key, \Closure $factory): mixed
    {
        if (isset($this->active[$key])) {
            throw new ConcurrentServiceInitializationException($key);
        }
        // @infection-ignore-all TrueValue — ekuivalen: guard membaca isset(active[key]);
        // isset(false) bernilai true sehingga deteksi independen nilai
        $this->active[$key] = true;

        try {
            return $factory();
        } finally {
            unset($this->active[$key]);
        }
    }
}
