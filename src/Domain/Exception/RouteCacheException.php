<?php

declare(strict_types=1);

/*
 * ZEF Framework v2.31.0 — Domain layer (ports, contracts, value objects)
 * Added in the v2.31.0 continuation (Regresi I-7, issue #175): a dedicated
 * exception for RouteCache read failures instead of the generic one, so a
 * stale or corrupt route-cache artifact is catchable by type.
 */

namespace Zef\Framework\Exception;

final class RouteCacheException extends \RuntimeException {}
