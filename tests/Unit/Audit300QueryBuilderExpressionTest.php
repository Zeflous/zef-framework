<?php

declare(strict_types=1);

/*
 * Audit #300 regression: SqlExpression in a WHERE value position must be
 * INLINED into the SQL, never bound as a parameter (PDO casts the object
 * via __toString and compares against the literal 'NOW()' string, silently
 * matching wrong rows). Covers renderCondition(), whereBetween() and the
 * same latent pattern in renderIn().
 */

namespace Zef\Test\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\QueryBuilder;
use Zef\Framework\Database\SqlExpression;

/**
 * @internal
 */
final class Audit300QueryBuilderExpressionTest extends TestCase
{
    public function testWhereValuePositionInlinesExpression(): void
    {
        $q = QueryBuilder::table('t')
            ->where('updated_at', '>=', new SqlExpression('NOW()'))
        ;
        $built = $q->build();

        self::assertSame('SELECT * FROM "t" WHERE "updated_at" >= NOW()', $built->sql);
        self::assertSame([], $built->params, 'an inlined expression must not appear in the binding list');
    }

    public function testWhereScalarValueStillBinds(): void
    {
        $q = QueryBuilder::table('t')->where('n', '>', 7);

        self::assertSame('SELECT * FROM "t" WHERE "n" > ?', $q->toSql());
        self::assertSame([7], $q->build()->params);
    }

    public function testWhereBetweenInlinesExpressionsAndKeepsScalarBindings(): void
    {
        $q = QueryBuilder::table('t')->whereBetween('n', 1, new SqlExpression('MAX(n)'));
        $built = $q->build();

        self::assertSame('SELECT * FROM "t" WHERE "n" BETWEEN ? AND MAX(n)', $built->sql);
        self::assertSame([1], $built->params);
    }

    public function testWhereBetweenBothExpressionsBindNothing(): void
    {
        $q = QueryBuilder::table('t')->whereBetween('n', new SqlExpression('MIN(n)'), new SqlExpression('MAX(n)'));
        $built = $q->build();

        self::assertSame('SELECT * FROM "t" WHERE "n" BETWEEN MIN(n) AND MAX(n)', $built->sql);
        self::assertSame([], $built->params);
    }

    public function testWhereInInlinesExpressionsAmongScalars(): void
    {
        $q = QueryBuilder::table('t')->whereIn('n', [1, new SqlExpression('(SELECT 7)'), 9]);
        $built = $q->build();

        self::assertSame('SELECT * FROM "t" WHERE "n" IN (?, (SELECT 7), ?)', $built->sql);
        self::assertSame([1, 9], $built->params);
    }

    /**
     * Runtime PoC from the issue (real PDO + SQLite): the expression must
     * participate in the comparison, not be bound as the string literal.
     */
    public function testPdoRuntimeExpressionMatchesTheIntendedRows(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (n INTEGER NOT NULL)');
        $insert = $pdo->prepare('INSERT INTO t (n) VALUES (?)');
        foreach ([5, 6, 7, 8, 9, 10] as $n) {
            $insert->execute([$n]);
        }

        $built = QueryBuilder::table('t')
            ->select('n')
            ->where('n', '>=', new SqlExpression('(SELECT 7)'))
            ->build()
        ;
        $stmt = $pdo->prepare($built->sql);
        $stmt->execute($built->params);
        $rows = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        // (SELECT 7) evaluates to 7 -> rows 7..10. The old bug bound the
        // object as the string '(SELECT 7)', SQLite compared n >= 0 and
        // returned all six rows.
        self::assertSame([7, 8, 9, 10], array_map('intval', $rows));
    }
}
