<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.7.0 — Domain layer (ports, contracts, value objects)
 * Extracted from monolith zef_framework_v2.7.0.php during the
 * hexagonal refactor (move-only, no behavioural changes).
 */

namespace Zef\Framework\Exception;

use Psr\Container\NotFoundExceptionInterface;

final class ServiceNotFoundException extends \RuntimeException implements NotFoundExceptionInterface
{
    public function __construct(
        public readonly string $serviceId,
        public readonly ?string $module = null,
        ?string $hint = null,
    ) {
        $suffix = $module !== null ? " (module: '{$module}')" : '';
        $message = "Service '{$serviceId}' not found{$suffix}.";
        if ($hint !== null) {
            $message .= ' ' . $hint;
        }
        parent::__construct($message);
    }
}
