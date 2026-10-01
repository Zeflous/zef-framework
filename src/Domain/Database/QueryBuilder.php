<?php

declare(strict_types=1);

/*
 * ZEF Framework — Database (Domain layer: ports, contracts, value objects)
 * Added in v2.18.0 (Database Core: query builder, PDO adapter, migrations).
 */

namespace Zef\Framework\Database;

/**
 * Fluent, driver-agnostic SQL query builder producing {@see SqlQuery}
 * value objects with strictly ordered positional parameters.
 *
 * Design rules:
 * - every identifier (table, column, alias) is validated against a strict
 *   grammar and double-quoted — user input can never become SQL syntax;
 * - string column arguments must be plain identifiers (optionally
 *   `table.column` or `identifier AS alias`); anything dynamic is passed
 *   as an explicit {@see SqlExpression} so raw SQL stays greppable;
 * - all values become `?` placeholders; null/bool/int/float/string are
 *   accepted, everything else is rejected;
 * - UPDATE/DELETE without WHERE must call {@see allowUnbounded()} first —
 *   accidental full-table writes are a bug, not a feature;
 * - output is deterministic: same builder state, same SQL string.
 */
final class QueryBuilder
{
    private const string IDENT_RE = '/^[A-Za-z_][A-Za-z0-9_]{0,63}$/';
    private const int MAX_IDENTIFIER_BYTES = 64;

    private const string TYPE_SELECT = 'select';
    private const string TYPE_INSERT = 'insert';
    private const string TYPE_UPDATE = 'update';
    private const string TYPE_DELETE = 'delete';

    private const array JOIN_TYPES = ['inner', 'left', 'right', 'cross'];
    private const array COMPARISON_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>='];
    private const array AGGREGATE_FUNCTIONS = ['COUNT', 'SUM', 'AVG', 'MIN', 'MAX'];

    private string $type = self::TYPE_SELECT;
    private ?string $table = null;
    private ?string $tableAlias = null;
    private bool $distinct = false;
    private bool $unboundedAllowed = false;

    /** @var list<string> */
    private array $selectColumns = [];

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $whereParts = [];

    /** @var list<mixed> */
    private array $params = [];

    /** @var list<string> */
    private array $groupBy = [];

    /** @var list<string> */
    private array $havingParts = [];

    /** @var list<string> */
    private array $orderBy = [];
    private ?int $limit = null;
    private ?int $offset = null;

    /** @var list<array<string, mixed>> */
    private array $insertRows = [];

    /** @var array<string, mixed> */
    private array $updatePairs = [];

    public static function table(SqlExpression|string $table, ?string $alias = null): self
    {
        $qb = new self();
        $qb->table = $qb->quoteTable($table);
        if ($alias !== null) {
            $qb->tableAlias = $qb->quoteIdentifier($alias, 'table alias');
        }

        return $qb;
    }

    public function select(SqlExpression|string ...$columns): self
    {
        $this->assertSelect('select()');
        if ($columns === []) {
            throw new QueryException('select() requires at least one column.');
        }
        $this->selectColumns = [];
        foreach ($columns as $column) {
            $this->selectColumns[] = $this->renderColumn($column);
        }

        return $this;
    }

    public function selectRaw(string $expression): self
    {
        return $this->select(new SqlExpression($expression));
    }

    public function distinct(bool $on = true): self
    {
        $this->assertSelect('distinct()');
        $this->distinct = $on;

        return $this;
    }

    /**
     * Aggregate sugar producing `SELECT <FUNC>(col) AS aggregate FROM ...`.
     */
    public function aggregate(string $function, string $column = '*'): self
    {
        $func = strtoupper($function);
        if (!in_array($func, self::AGGREGATE_FUNCTIONS, true)) {
            throw new QueryException(
                "Unknown aggregate function '{$function}' (allowed: "
                . implode(', ', self::AGGREGATE_FUNCTIONS) . ').',
            );
        }
        $inner = $column === '*' ? '*' : $this->quoteColumnPath($column);

        return $this->select(new SqlExpression($func . '(' . $inner . ') AS aggregate'));
    }

    public function count(string $column = '*'): self
    {
        return $this->aggregate('COUNT', $column);
    }

    public function join(
        SqlExpression|string $table,
        string $first,
        string $operator,
        string $second,
        string $type = 'inner',
    ): self {
        $this->assertSelect('join()');
        $type = strtolower($type);
        if (!in_array($type, self::JOIN_TYPES, true)) {
            throw new QueryException(
                "Unknown join type '{$type}' (allowed: " . implode(', ', self::JOIN_TYPES) . ').',
            );
        }
        $op = $this->assertComparisonOperator($operator);
        $keyword = $type === 'inner' ? 'INNER JOIN' : strtoupper($type . ' JOIN');

        $this->joins[] = $keyword . ' ' . $this->quoteTable($table)
            . ' ON ' . $this->quoteColumnPath($first) . ' ' . $op . ' ' . $this->quoteColumnPath($second);

        return $this;
    }

    public function leftJoin(SqlExpression|string $table, string $first, string $operator, string $second): self
    {
        return $this->join($table, $first, $operator, $second, 'left');
    }

    /**
     * AND condition. Two-argument form is rejected: equality is spelled
     * `where('col', '=', $value)` and NULL checks use whereNull().
     */
    public function where(SqlExpression|string $column, string $operator, mixed $value): self
    {
        return $this->addWhere($this->renderCondition($column, $operator, $value), 'AND');
    }

    /** AND condition comparing two columns of the same row (no params). */
    public function whereColumn(string $first, string $operator, string $second): self
    {
        $op = $this->assertComparisonOperator($operator);

        return $this->addWhere(
            $this->quoteColumnPath($first) . ' ' . $op . ' ' . $this->quoteColumnPath($second),
            'AND',
        );
    }

    /** OR condition against the previous condition. */
    public function orWhere(SqlExpression|string $column, string $operator, mixed $value): self
    {
        return $this->addWhere($this->renderCondition($column, $operator, $value), 'OR');
    }

    /**
     * Group conditions in parentheses, ANDed with the outer chain.
     *
     * @param callable(QueryBuilder): void $fn
     */
    public function whereNested(callable $fn): self
    {
        $inner = new self();
        $fn($inner);
        if ($inner->whereParts === []) {
            throw new QueryException('whereNested() callback must add at least one condition.');
        }
        $fragment = '(' . implode(' ', $inner->whereParts) . ')';
        $this->params = array_merge($this->params, $inner->params);

        return $this->addWhere($fragment, 'AND');
    }

    /**
     * @param list<mixed>|QueryBuilder $values
     */
    public function whereIn(string $column, array|QueryBuilder $values): self
    {
        return $this->addWhere($this->renderIn($column, $values, false), 'AND');
    }

    /**
     * @param list<mixed>|QueryBuilder $values
     */
    public function whereNotIn(string $column, array|QueryBuilder $values): self
    {
        return $this->addWhere($this->renderIn($column, $values, true), 'AND');
    }

    public function whereNull(string $column): self
    {
        return $this->addWhere($this->quoteColumnPath($column) . ' IS NULL', 'AND');
    }

    public function whereNotNull(string $column): self
    {
        return $this->addWhere($this->quoteColumnPath($column) . ' IS NOT NULL', 'AND');
    }

    public function whereBetween(string $column, mixed $low, mixed $high): self
    {
        $this->assertScalar($low, 'whereBetween() low');
        $this->assertScalar($high, 'whereBetween() high');
        // Audit #300: a SqlExpression bound as a parameter is cast by PDO to
        // its literal string ("BETWEEN 'NOW()' AND ..."), silently matching
        // wrong rows. Inline expressions exactly like buildInsert()/buildUpdate().
        $lowSql = $low instanceof SqlExpression ? (string) $low : '?';
        $highSql = $high instanceof SqlExpression ? (string) $high : '?';
        if (!$low instanceof SqlExpression) {
            $this->params[] = $low;
        }
        if (!$high instanceof SqlExpression) {
            $this->params[] = $high;
        }

        return $this->addWhere($this->quoteColumnPath($column) . " BETWEEN {$lowSql} AND {$highSql}", 'AND');
    }

    /**
     * @param bool $escapeWildcards when true, % _ and \ in $pattern are
     *                              escaped and the ESCAPE clause is emitted
     */
    public function whereLike(string $column, string $pattern, bool $escapeWildcards = false): self
    {
        if ($pattern === '') {
            throw new QueryException('whereLike() pattern must not be empty.');
        }
        $escape = '';
        if ($escapeWildcards) {
            $pattern = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $pattern);
            $escape = " ESCAPE '\\'";
        }
        $this->params[] = $pattern;

        return $this->addWhere($this->quoteColumnPath($column) . ' LIKE ?' . $escape, 'AND');
    }

    public function groupBy(SqlExpression|string ...$columns): self
    {
        $this->assertSelect('groupBy()');
        if ($columns === []) {
            throw new QueryException('groupBy() requires at least one column.');
        }
        foreach ($columns as $column) {
            $this->groupBy[] = $this->renderColumn($column);
        }

        return $this;
    }

    public function having(SqlExpression|string $column, string $operator, mixed $value): self
    {
        $this->assertSelect('having()');
        $this->havingParts[] = $this->renderCondition($column, $operator, $value);

        return $this;
    }

    public function orderBy(SqlExpression|string $column, string $direction = 'ASC'): self
    {
        $this->assertSelect('orderBy()');
        $dir = strtoupper($direction);
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            throw new QueryException("Unknown order direction '{$direction}' (allowed: ASC, DESC).");
        }
        $this->orderBy[] = $this->renderColumn($column) . ' ' . $dir;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->assertSelect('limit()');
        if ($limit < 0) {
            throw new QueryException('limit() must be >= 0.');
        }
        $this->limit = $limit;

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->assertSelect('offset()');
        if ($offset < 0) {
            throw new QueryException('offset() must be >= 0.');
        }
        if ($this->limit === null) {
            throw new QueryException('offset() requires limit() to be set first.');
        }
        $this->offset = $offset;

        return $this;
    }

    /**
     * Single-row insert. Replaces any previously configured rows.
     *
     * @param array<string, mixed> $row
     */
    public function insert(array $row): self
    {
        return $this->insertRows([$row]);
    }

    /**
     * Multi-row insert; every row MUST carry the same column set.
     *
     * @param list<array<string, mixed>> $rows
     */
    public function insertRows(array $rows): self
    {
        if ($rows === []) {
            throw new QueryException('insertRows() requires at least one row.');
        }
        $this->markType(self::TYPE_INSERT, 'insertRows()');
        $expected = null;
        foreach ($rows as $i => $row) {
            if (!is_array($row) || $row === []) {
                throw new QueryException("insertRows() row {$i} must be a non-empty column => value map.");
            }
            foreach (array_keys($row) as $key) {
                if (!is_string($key)) {
                    throw new QueryException("insertRows() row {$i} column names must be strings.");
                }
            }
            if ($expected !== null && array_keys($row) !== $expected) {
                throw new QueryException("insertRows() row {$i} column set differs from the first row.");
            }
            $expected ??= array_keys($row);

            foreach ($row as $column => $value) {
                $this->quoteIdentifier($column, 'column');
                $this->assertScalar($value, "insertRows() row {$i} value for '{$column}'");
            }
        }
        $this->insertRows = array_values($rows);

        return $this;
    }

    /**
     * @param array<string, mixed> $pairs
     */
    public function update(array $pairs): self
    {
        $this->markType(self::TYPE_UPDATE, 'update()');
        if ($pairs === []) {
            throw new QueryException('update() requires at least one column => value pair.');
        }
        foreach ($pairs as $column => $value) {
            if (!is_string($column)) {
                throw new QueryException('update() column names must be strings.');
            }
            $this->quoteIdentifier($column, 'column');
            $this->assertScalar($value, "update() value for '{$column}'");
        }
        $this->updatePairs = $pairs;

        return $this;
    }

    public function delete(): self
    {
        $this->markType(self::TYPE_DELETE, 'delete()');

        return $this;
    }

    /**
     * Escape hatch for UPDATE/DELETE without WHERE (truncate-style ops).
     */
    public function allowUnbounded(bool $on = true): self
    {
        $this->unboundedAllowed = $on;

        return $this;
    }

    public function build(): SqlQuery
    {
        return match ($this->type) {
            self::TYPE_SELECT => $this->buildSelect(),
            self::TYPE_INSERT => $this->buildInsert(),
            self::TYPE_UPDATE => $this->buildUpdate(),
            self::TYPE_DELETE => $this->buildDelete(),
            default => throw new \LogicException("Unknown query type '{$this->type}'."),
        };
    }

    public function toSql(): string
    {
        return $this->build()->sql;
    }

    /**
     * @return list<mixed>
     */
    public function getBindings(): array
    {
        return $this->build()->params;
    }

    // ------------------------------------------------------------------
    // Identifier & value guards
    // ------------------------------------------------------------------

    /**
     * Validate and double-quote an identifier (max 64 bytes, strict grammar).
     */
    public function quoteIdentifier(string $identifier, string $context = 'identifier'): string
    {
        if ($identifier === '') {
            throw new QueryException("{$context} must not be empty.");
        }
        $bytes = strlen($identifier);
        if ($bytes > self::MAX_IDENTIFIER_BYTES) {
            throw new QueryException(
                "{$context} exceeds the " . self::MAX_IDENTIFIER_BYTES . "-byte limit (got {$bytes}).",
            );
        }
        if (preg_match(self::IDENT_RE, $identifier) !== 1) {
            $message = "Invalid {$context} '{$identifier}'.";
            // DX (v2.18.0 audit): make the SqlExpression escape hatch obvious
            // when a raw SQL fragment is mistakenly passed as an identifier —
            // keeps raw SQL greppable instead of inviting unsafe workarounds.
            if (preg_match('/[\s()*,<>=]/', $identifier) === 1) {
                $message .= implode('', [
                    ' Identifiers must match [A-Za-z_][A-Za-z0-9_]{0,63}',
                    ' (optionally table.column or column AS alias); for raw SQL pass an',
                    ' explicit SqlExpression instead — e.g. new SqlExpression(...),',
                    ' selectRaw(...), or SqlQuery::raw(...).',
                ]);
            }

            throw new QueryException($message);
        }

        return '"' . $identifier . '"';
    }

    private function quoteTable(SqlExpression|string $table): string
    {
        if ($table instanceof SqlExpression) {
            return (string) $table;
        }
        $segments = explode('.', $table);
        if (count($segments) > 2) {
            throw new QueryException("Invalid table '{$table}' (at most one dot is allowed).");
        }
        $quoted = array_map(fn (string $s): string => $this->quoteIdentifier(trim($s), 'table'), $segments);

        return implode('.', $quoted);
    }

    private function quoteColumnPath(string $column): string
    {
        $segments = explode('.', $column);
        if (count($segments) > 2) {
            throw new QueryException("Invalid column '{$column}' (at most one dot is allowed).");
        }
        $quoted = array_map(fn (string $s): string => $this->quoteIdentifier(trim($s), 'column'), $segments);

        return implode('.', $quoted);
    }

    /**
     * Render `col`, `table.col`, `path AS alias` or a raw SqlExpression.
     */
    private function renderColumn(SqlExpression|string $column): string
    {
        if ($column instanceof SqlExpression) {
            return (string) $column;
        }
        $trimmed = trim($column);
        if ($trimmed === '') {
            throw new QueryException('Column must not be empty.');
        }
        if (preg_match('/^([a-z0-9_.]+)\s+AS\s+([a-z_][a-z0-9_]{0,63})$/i', $trimmed, $m) === 1) {
            return $this->quoteColumnPath($m[1]) . ' AS ' . $this->quoteIdentifier($m[2], 'alias');
        }

        return $this->quoteColumnPath($trimmed);
    }

    private function assertComparisonOperator(string $operator): string
    {
        if (!in_array($operator, self::COMPARISON_OPERATORS, true)) {
            throw new QueryException(
                "Unknown comparison operator '{$operator}' (allowed: "
                . implode(' ', self::COMPARISON_OPERATORS) . ').',
            );
        }

        return $operator;
    }

    private function assertScalar(mixed $value, string $context): void
    {
        if ($value === null || is_scalar($value)) {
            return;
        }
        if ($value instanceof SqlExpression) {
            return;
        }

        throw new QueryException(
            "{$context} must be null, bool, int, float, string or SqlExpression (got " . get_debug_type($value) . ').',
        );
    }

    private function assertSelect(string $method): void
    {
        if ($this->type !== self::TYPE_SELECT) {
            throw new QueryException("{$method} is not available on a {$this->type} query.");
        }
    }

    private function markType(string $type, string $method): void
    {
        if ($this->type !== self::TYPE_SELECT && $this->type !== $type) {
            throw new QueryException("{$method} cannot be mixed with an existing {$this->type} query.");
        }
        $this->type = $type;
    }

    // ------------------------------------------------------------------
    // Condition assembly
    // ------------------------------------------------------------------

    private function renderCondition(SqlExpression|string $column, string $operator, mixed $value): string
    {
        $op = $this->assertComparisonOperator($operator);
        if ($value === null && ($op === '=' || $op === '<>')) {
            throw new QueryException(
                "Use whereNull()/whereNotNull() instead of comparing '{$column}' with {$op} null.",
            );
        }
        $this->assertScalar($value, "condition value for '{$column}'");
        $left = $column instanceof SqlExpression ? (string) $column : $this->quoteColumnPath($column);
        // Audit #300: inline the escape hatch in value position — binding the
        // object makes PDO compare against the literal 'NOW()' string.
        if ($value instanceof SqlExpression) {
            return $left . ' ' . $op . ' ' . (string) $value;
        }
        $this->params[] = $value;

        return $left . ' ' . $op . ' ?';
    }

    /**
     * @param list<mixed>|QueryBuilder $values
     */
    private function renderIn(string $column, array|QueryBuilder $values, bool $negated): string
    {
        $not = $negated ? 'NOT ' : '';
        if ($values instanceof QueryBuilder) {
            $sub = $values->build();
            $this->params = array_merge($this->params, $sub->params);

            return $this->quoteColumnPath($column) . ' ' . $not . 'IN (' . $sub->sql . ')';
        }
        if ($values === []) {
            throw new QueryException('whereIn() requires a non-empty list of values.');
        }
        $placeholders = [];
        foreach ($values as $value) {
            $this->assertScalar($value, 'whereIn() value');
            if ($value instanceof SqlExpression) {
                // Audit #300 (same pattern as renderCondition): inline the
                // expression instead of binding the object as a string.
                $placeholders[] = (string) $value;
            } else {
                $this->params[] = $value;
                $placeholders[] = '?';
            }
        }

        return $this->quoteColumnPath($column) . ' ' . $not . 'IN (' . implode(', ', $placeholders) . ')';
    }

    private function addWhere(string $fragment, string $connector): self
    {
        if ($this->type === self::TYPE_INSERT) {
            throw new QueryException('WHERE clauses are not available on an insert query.');
        }
        if ($this->whereParts !== []) {
            $this->whereParts[] = $connector . ' ' . $fragment;
        } else {
            $this->whereParts[] = $fragment;
        }

        return $this;
    }

    // ------------------------------------------------------------------
    // SQL assembly
    // ------------------------------------------------------------------

    private function buildSelect(): SqlQuery
    {
        if ($this->table === null) {
            throw new QueryException('Select query requires a table (call QueryBuilder::table() first).');
        }
        $columns = $this->selectColumns === [] ? ['*'] : $this->selectColumns;
        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $columns)
            . ' FROM ' . $this->table;
        if ($this->tableAlias !== null) {
            $sql .= ' AS ' . $this->tableAlias;
        }
        foreach ($this->joins as $join) {
            $sql .= ' ' . $join;
        }
        $sql .= $this->renderWhereSuffix();
        if ($this->groupBy !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groupBy);
        }
        if ($this->havingParts !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havingParts);
        }
        if ($this->orderBy !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orderBy);
        }
        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }
        if ($this->offset !== null) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return new SqlQuery($sql, $this->params);
    }

    private function buildInsert(): SqlQuery
    {
        if ($this->table === null) {
            throw new QueryException('Insert query requires a table (call QueryBuilder::table() first).');
        }
        if ($this->insertRows === []) {
            throw new QueryException('Insert query requires data (call insert()/insertRows() first).');
        }
        $columns = [];
        foreach (array_keys($this->insertRows[0]) as $column) {
            $columns[] = $this->quoteIdentifier((string) $column, 'column');
        }
        $rowSql = [];
        $bindings = [];
        foreach ($this->insertRows as $row) {
            $rowParams = [];
            foreach ($row as $value) {
                if ($value instanceof SqlExpression) {
                    $rowParams[] = (string) $value;
                } else {
                    $rowParams[] = '?';
                    $bindings[] = $value;
                }
            }
            $rowSql[] = '(' . implode(', ', $rowParams) . ')';
        }
        $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $columns) . ') VALUES ' . implode(', ', $rowSql);

        return new SqlQuery($sql, $bindings);
    }

    private function buildUpdate(): SqlQuery
    {
        if ($this->table === null) {
            throw new QueryException('Update query requires a table (call QueryBuilder::table() first).');
        }
        if ($this->updatePairs === []) {
            throw new QueryException('Update query requires data (call update() first).');
        }
        if ($this->whereParts === [] && !$this->unboundedAllowed) {
            throw new QueryException(
                'Refusing unbounded UPDATE without WHERE; call allowUnbounded() to confirm a full-table update.',
            );
        }
        $sets = [];
        $bindings = [];
        foreach ($this->updatePairs as $column => $value) {
            if ($value instanceof SqlExpression) {
                $sets[] = $this->quoteIdentifier((string) $column, 'column') . ' = ' . $value;
            } else {
                $sets[] = $this->quoteIdentifier((string) $column, 'column') . ' = ?';
                $bindings[] = $value;
            }
        }
        $sql = 'UPDATE ' . $this->table . ' SET ' . implode(', ', $sets) . $this->renderWhereSuffix();

        return new SqlQuery(
            $sql,
            array_merge($bindings, $this->params),
            $this->whereParts === [], // only reached when allowUnbounded() was set
        );
    }

    private function buildDelete(): SqlQuery
    {
        if ($this->table === null) {
            throw new QueryException('Delete query requires a table (call QueryBuilder::table() first).');
        }
        if ($this->whereParts === [] && !$this->unboundedAllowed) {
            throw new QueryException(
                'Refusing unbounded DELETE without WHERE; call allowUnbounded() to confirm a full-table delete.',
            );
        }
        $sql = 'DELETE FROM ' . $this->table . $this->renderWhereSuffix();

        return new SqlQuery(
            $sql,
            $this->params,
            $this->whereParts === [], // only reached when allowUnbounded() was set
        );
    }

    private function renderWhereSuffix(): string
    {
        if ($this->whereParts === []) {
            return '';
        }

        return ' WHERE ' . implode(' ', $this->whereParts);
    }
}
