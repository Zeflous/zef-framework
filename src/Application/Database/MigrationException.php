<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Application layer: services over the port)
 * Dedicated exception for the versioned migration runner (php:S112:
 * replaces the generic RuntimeException thrown when an applied migration
 * is missing from the runner's registry). Extends DatabaseException —
 * transitively \RuntimeException — so existing catch blocks and tests
 * keep working unchanged.
 */

namespace Zef\Framework\Database;

final class MigrationException extends DatabaseException {}
