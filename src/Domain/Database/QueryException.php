<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Raised when statement preparation or execution fails, or when query
 * construction violates the builder grammar (invalid identifiers, empty
 * IN() lists, missing table/data, unbounded UPDATE/DELETE without the
 * explicit escape hatch).
 *
 * ZEF-DX-09 (issue #249): the failing SQL travels as STRUCTURED CONTEXT
 * (getSql()), never inside the message. Exception messages flow to error
 * response bodies in debug mode, so embedding the statement there leaked
 * table/column names and data literals to HTTP clients.
 */
final class QueryException extends DatabaseException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly ?string $sql = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /** The statement that failed, when the failure came from the PDO adapter. */
    public function getSql(): ?string
    {
        return $this->sql;
    }
}
