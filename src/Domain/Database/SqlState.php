<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added for issue #170 (ZEF-DEEP-16): shared SQLSTATE classifier for the
 * unique-constraint backstops of the PDO adapters.
 */

namespace Zef\Framework\Database;

/**
 * Classifies {@see QueryException} throwables that carry a unique-constraint
 * violation — the failure mode every "MAX(...) + 1 inside a transaction"
 * backstop (event store stream/global indexes, snapshot identity, job queue
 * seq) surfaces when its in-transaction guard loses a concurrent race.
 *
 * PdoConnection wraps the driver's PDOException as QueryException: the
 * SQLSTATE ends up in getCode() (PostgreSQL reports the SQLSTATE there,
 * MySQL its driver number) and the original — whose errorInfo[0]/[1] slots
 * carry the SQLSTATE and the driver code — stays chained as the previous
 * throwable. Unique violations hide behind three dialects:
 *
 * - PostgreSQL: SQLSTATE 23505 (unique_violation), also in the message;
 * - MySQL/MariaDB: SQLSTATE 23000 with driver code 1062 (ER_DUP_ENTRY),
 *   "Duplicate entry '…' for key '…'";
 * - SQLite: a flat SQLSTATE 23000 for EVERY integrity error (NOT NULL,
 *   CHECK, FK and UNIQUE alike) — only the "UNIQUE constraint failed: …"
 *   message text separates them, which is why the message check runs first.
 *
 * Mirrors the SQLSTATE matching of {@see UnitOfWorkRetryPolicy} (transient
 * failures) for the constraint side of the same adapters.
 */
final class SqlState
{
    private function __construct() {}

    /**
     * True when the error is a unique-constraint violation.
     *
     * Narrow by construction: same-SQLSTATE-family errors (NOT NULL, CHECK,
     * FK) never match, because neither their message text nor their codes
     * claim uniqueness.
     */
    public static function isUniqueViolation(QueryException $error): bool
    {
        $previous = $error->getPrevious();
        if (!$previous instanceof \PDOException) {
            return false;
        }

        $message = $previous->getMessage();
        if (str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'Duplicate entry')
            || str_contains($message, 'duplicate key value violates unique constraint')
        ) {
            return true;
        }

        // Code fallback for rewritable driver messages: SQLSTATE 23505 (SQL
        // standard unique_violation, PostgreSQL) and MySQL driver code 1062
        // (ER_DUP_ENTRY, reported as SQLSTATE 23000). SQLite folds every
        // integrity error into 23000, so the code path alone can never prove
        // uniqueness there — hence the message check above.
        $codes = [(string) $error->getCode()];
        if (isset($previous->errorInfo) && is_array($previous->errorInfo)) {
            foreach ([0, 1] as $offset) {
                $code = $previous->errorInfo[$offset] ?? null;
                if (is_int($code) || is_string($code)) {
                    $codes[] = (string) $code;
                }
            }
        }

        return in_array('23505', $codes, true) || in_array('1062', $codes, true);
    }
}
