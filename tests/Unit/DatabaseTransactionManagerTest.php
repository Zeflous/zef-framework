<?php

declare(strict_types=1);

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Database\ConnectionConfig;
use Zef\Framework\Database\ConnectionException;
use Zef\Framework\Database\ConnectionInterface;
use Zef\Framework\Database\IsolationLevel;
use Zef\Framework\Database\PdoConnection;
use Zef\Framework\Database\SqlQuery;
use Zef\Framework\Database\TransactionException;
use Zef\Framework\Database\TransactionManager;
use Zef\Framework\Database\TransactionManagerInterface;

/**
 * v2.22.0 — Transaction orchestration: TransactionManager over the
 * connection primitives (real SQLite integration + controlled fakes for
 * hook-lifecycle edges).
 *
 * @internal
 */
final class DatabaseTransactionManagerTest extends TestCase
{
    private ConnectionInterface $conn;

    private TransactionManagerInterface $tx;

    protected function setUp(): void
    {
        $this->conn = new PdoConnection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'dbname' => ':memory:',
        ]));
        $this->conn->execute(SqlQuery::raw('CREATE TABLE t (id INTEGER PRIMARY KEY, name TEXT)'));
        $this->tx = new TransactionManager($this->conn);
    }

    // ------------------------------------------------------------------
    // Commit / rollback / nesting
    // ------------------------------------------------------------------

    public function testWithTransactionCommitsOnSuccess(): void
    {
        $result = $this->tx->withTransaction(function (ConnectionInterface $conn): string {
            $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('inside')"));

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(['inside'], $this->names());
        self::assertSame(0, $this->tx->level());
        self::assertFalse($this->tx->inTransaction());
    }

    public function testWithTransactionRollsBackOnThrowable(): void
    {
        try {
            $this->tx->withTransaction(function (ConnectionInterface $conn): never {
                $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('doomed')"));

                throw new \RuntimeException('boom');
            });
            // @phpstan-ignore-next-line (the scope always throws on this path)
            self::fail('Expected the runtime exception to propagate.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $this->names());
        self::assertSame(0, $this->tx->level(), 'scope depth must unwind on failure');
    }

    public function testNestedScopeRollbackKeepsOuterWrites(): void
    {
        $this->tx->withTransaction(function (ConnectionInterface $conn): void {
            $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('outer')"));

            try {
                $this->tx->withTransaction(function (ConnectionInterface $inner): never {
                    $inner->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('inner')"));

                    throw new \RuntimeException('inner fails');
                });
                // @phpstan-ignore-next-line (the scope always throws on this path)
                self::fail('Expected inner failure to propagate.');
            } catch (\RuntimeException) {
            }
            self::assertSame(1, $this->tx->level(), 'inner scope must have unwound');
        });

        self::assertSame(['outer'], $this->names(), 'savepoint rollback keeps outer writes');
    }

    public function testIsolationRejectedBySqliteSurfacesAsConnectionException(): void
    {
        try {
            $this->tx->withTransaction(static fn (ConnectionInterface $c): null => null, IsolationLevel::Serializable);
            self::fail('SQLite must reject isolation levels.');
        } catch (ConnectionException) {
            self::assertSame([], $this->names(), 'failed begin must not leave writes');
        }
    }

    // ------------------------------------------------------------------
    // afterCommit scheduling
    // ------------------------------------------------------------------

    public function testAfterCommitRunsImmediatelyOutsideManagedScope(): void
    {
        $calls = [];
        $this->tx->afterCommit(function () use (&$calls): void {
            $calls[] = 'immediate';
        });

        self::assertSame(['immediate'], $calls);
        self::assertFalse($this->tx->inTransaction());
    }

    public function testHooksFlushFifoAfterOutermostCommit(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('row')"));
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'first';
            });
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'second';
            });
            self::assertSame([], $order, 'hooks must not run before the commit');
        });

        self::assertSame(['first', 'second'], $order);
        self::assertSame(['row'], $this->names());
    }

    public function testHooksFromNestedScopesFlushAfterOutermostCommit(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $outer) use (&$order): void {
            $outer->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('a')"));
            $this->tx->withTransaction(function (ConnectionInterface $inner) use (&$order): void {
                $inner->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('b')"));
                $this->tx->afterCommit(function () use (&$order): void {
                    $order[] = 'from-inner';
                });
            });
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'from-outer';
            });
        });

        self::assertSame(['from-inner', 'from-outer'], $order);
        self::assertSame(['a', 'b'], $this->names());
    }

    public function testOutermostRollbackDiscardsAllHooks(): void
    {
        $fired = [];

        try {
            $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$fired): never {
                $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('x')"));
                $this->tx->afterCommit(function () use (&$fired): void {
                    $fired[] = 'never';
                });

                throw new \RuntimeException('rollback path');
            });
            // @phpstan-ignore-next-line (the scope always throws on this path)
            self::fail('Expected failure.');
        } catch (\RuntimeException) {
        }

        self::assertSame([], $fired, 'rolled-back scope must discard every hook');
        self::assertSame([], $this->names());
    }

    public function testHookRegisteredDuringFlushRunsInline(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'outer-hook';
                $this->tx->afterCommit(function () use (&$order): void {
                    $order[] = 'inline-child';
                });
                $order[] = 'outer-hook-end';
            });
        });

        self::assertSame(['outer-hook', 'inline-child', 'outer-hook-end'], $order);
    }

    public function testInnerScopeFailurePreservesHooksQueuedByOuterScope(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('outer')"));
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'outer-hook';
            });
            $this->tx->withTransaction(function (ConnectionInterface $inner) use (&$order): void {
                $this->tx->afterCommit(function () use (&$order): void {
                    $order[] = 'successful-sibling';
                });
            });

            try {
                $this->tx->withTransaction(function (ConnectionInterface $inner) use (&$order): never {
                    $inner->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('rolled-back-inner')"));
                    $this->tx->afterCommit(function () use (&$order): void {
                        $order[] = 'rolled-back-inner';
                    });

                    throw new \RuntimeException('inner fails');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('inner fails', $e->getMessage());
            }
            self::assertSame(1, $this->tx->level());
            self::assertSame(1, $conn->transactionLevel());
            self::assertTrue($this->tx->inTransaction());
            self::assertSame([], $order, 'surviving hooks must wait for the outer commit');
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'outer-after-rollback';
            });
        });

        self::assertSame(['outer-hook', 'successful-sibling', 'outer-after-rollback'], $order);
        self::assertSame(['outer'], $this->names());
        self::assertSame(0, $this->tx->level());
        self::assertSame(0, $this->conn->transactionLevel());
        self::assertFalse($this->tx->inTransaction());

        $this->tx->withTransaction(static fn (ConnectionInterface $conn): null => null);
        self::assertSame(['outer-hook', 'successful-sibling', 'outer-after-rollback'], $order, 'hooks must not leak into a later transaction');
    }

    public function testNestedRollbackDiscardsHooksFromSuccessfulDescendantScopes(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            try {
                $this->tx->withTransaction(function (ConnectionInterface $inner) use (&$order): never {
                    $this->tx->afterCommit(function () use (&$order): void {
                        $order[] = 'rolled-back-parent';
                    });
                    $this->tx->withTransaction(function (ConnectionInterface $descendant) use (&$order): void {
                        $descendant->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('descendant')"));
                        $this->tx->afterCommit(function () use (&$order): void {
                            $order[] = 'rolled-back-descendant';
                        });
                    });

                    throw new \RuntimeException('parent fails');
                });
            } catch (\RuntimeException $e) {
                self::assertSame('parent fails', $e->getMessage());
            }
            self::assertSame([], $order);
            self::assertSame(1, $this->tx->level());
            $this->tx->withTransaction(function (ConnectionInterface $sibling) use (&$order): void {
                $sibling->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('sibling')"));
                $this->tx->afterCommit(function () use (&$order): void {
                    $order[] = 'surviving-sibling';
                });
            });
        });

        self::assertSame(['surviving-sibling'], $order);
        self::assertSame(['sibling'], $this->names());
        self::assertSame(0, $this->tx->level());
        self::assertSame(0, $this->conn->transactionLevel());
    }

    public function testImmediateHookIsNotRequeuedForNextScope(): void
    {
        $fires = 0;
        $this->tx->afterCommit(function () use (&$fires): void {
            ++$fires;
        });

        self::assertSame(1, $fires);
        $this->tx->withTransaction(static fn (ConnectionInterface $conn): null => null);

        self::assertSame(1, $fires, 'immediate hook must not fire again at the next commit');
    }

    public function testHookOpeningItsOwnScopeSchedulesInlineDuringDrain(): void
    {
        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'hook-start';
                $this->tx->withTransaction(function (ConnectionInterface $c) use (&$order): void {
                    $this->tx->afterCommit(function () use (&$order): void {
                        $order[] = 'nested-inline';
                    });
                    $order[] = 'scope-body';
                });
                $order[] = 'hook-end';
            });
        });

        self::assertSame(['hook-start', 'nested-inline', 'scope-body', 'hook-end'], $order);
    }

    public function testFlushingFlagResetsAfterDrainFailure(): void
    {
        $hookThrew = false;

        try {
            $this->tx->withTransaction(function (ConnectionInterface $conn): void {
                $this->tx->afterCommit(static function (): never {
                    throw new TransactionException('hook fail');
                });
            });
        } catch (TransactionException) {
            $hookThrew = true;
        }
        self::assertTrue($hookThrew, 'Expected the hook failure to surface.');

        $order = [];
        $this->tx->withTransaction(function (ConnectionInterface $conn) use (&$order): void {
            $this->tx->afterCommit(function () use (&$order): void {
                $order[] = 'second';
            });
            $order[] = 'scope-body';
        });

        self::assertSame(['scope-body', 'second'], $order, 'a failed drain must not leave scheduling inline');
    }

    public function testHookThrowingPropagatesAfterCommitApplied(): void
    {
        try {
            $this->tx->withTransaction(function (ConnectionInterface $conn): void {
                $conn->execute(SqlQuery::raw("INSERT INTO t (name) VALUES ('persisted')"));
                $this->tx->afterCommit(static function (): never {
                    throw new TransactionException('hook failed');
                });
            });
            self::fail('Expected the hook failure to surface.');
        } catch (TransactionException) {
        }

        self::assertSame(['persisted'], $this->names(), 'commit is applied even when a hook fails');
    }

    public function testScopeDepthAndInTransactionTracking(): void
    {
        self::assertFalse($this->tx->inTransaction());
        self::assertSame(0, $this->tx->level());
        $this->tx->withTransaction(function (ConnectionInterface $conn): void {
            self::assertTrue($this->tx->inTransaction());
            self::assertSame(1, $this->tx->level());
            $this->tx->withTransaction(function (ConnectionInterface $inner): void {
                self::assertSame(2, $this->tx->level());
            });
            self::assertSame(1, $this->tx->level());
        });
        self::assertSame(0, $this->tx->level());
    }

    /** @return list<string> */
    private function names(): array
    {
        $names = [];
        foreach ($this->conn->fetchAll(SqlQuery::raw('SELECT name FROM t ORDER BY id')) as $row) {
            self::assertIsString($row['name']);
            $names[] = $row['name'];
        }

        return $names;
    }
}
