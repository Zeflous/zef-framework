<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Domain/Validation/DependencyGraphValidator.php (13 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\Exception\ModuleDependencyViolationException;
use Zef\Framework\Exception\ServiceCircularDependencyException;
use Zef\Framework\Validation\DependencyGraphValidator;

/**
 * @internal
 */
final class DependencyGraphValidatorMutantKillTest extends TestCase
{
    private function validator(): DependencyGraphValidator
    {
        return new DependencyGraphValidator();
    }

    /** Cross-module budget message is pinned verbatim (kills Concat:118/119). */
    public function testCrossModuleBudgetMessageIsPinned(): void
    {
        $this->expectException(ModuleDependencyViolationException::class);
        $this->expectExceptionMessage("Module 'M1' exceeds cross-module reference limit (1) towards 'M2'.");

        $this->validator()->validate(
            ['A' => 1, 'B' => 1, 'C' => 1],
            [],
            ['A' => ['B', 'C']],
            ['A' => 'M1', 'B' => 'M2', 'C' => 'M2'],
            ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT, 'C' => ServiceLifetime::TRANSIENT],
            1,
        );
    }

    /** Exactly at the limit passes; one over throws (kills LogicalOr:115, TrueValue:123). */
    public function testCrossModuleBudgetBoundary(): void
    {
        $lifetimes = ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT, 'C' => ServiceLifetime::TRANSIENT];

        // One distinct target at limit 1 -> OK.
        $this->validator()->validate(
            ['A' => 1, 'B' => 1],
            [],
            ['A' => ['B']],
            ['A' => 'M1', 'B' => 'M2'],
            $lifetimes,
            1,
        );
        self::assertTrue(true, 'one cross-module ref at the limit must pass');

        // Two distinct targets at limit 1 -> throw.
        $this->expectException(ModuleDependencyViolationException::class);
        $this->validator()->validate(
            ['A' => 1, 'B' => 1, 'C' => 1],
            [],
            ['A' => ['B', 'C']],
            ['A' => 'M1', 'B' => 'M2', 'C' => 'M2'],
            $lifetimes,
            1,
        );
    }

    /** Repeated refs to the SAME target count once (kills TrueValue:123). */
    public function testRepeatedRefToSameTargetCountsOnce(): void
    {
        $this->validator()->validate(
            ['A' => 1, 'B' => 1],
            [],
            ['A' => ['B', 'B', 'B']],
            ['A' => 'M1', 'B' => 'M2'],
            ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT],
            1,
        );
        self::assertTrue(true, 'N refs to one target count once per target');
    }

    /** Same-module refs never count against the budget (kills LogicalOr:115). */
    public function testSameModuleRefsDoNotCount(): void
    {
        $this->validator()->validate(
            ['A' => 1, 'B' => 1, 'C' => 1],
            [],
            ['A' => ['B', 'C']],
            ['A' => 'M1', 'B' => 'M1', 'C' => 'M1'],
            ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT, 'C' => ServiceLifetime::TRANSIENT],
            1,
        );
        self::assertTrue(true, 'intra-module refs are exempt from the cross-module budget');
    }

    /** Null-module refs never count against the budget (kills LogicalOr:115). */
    public function testNullModuleRefsDoNotCount(): void
    {
        $this->validator()->validate(
            ['A' => 1, 'B' => 1, 'C' => 1],
            [],
            ['A' => ['B', 'C']],
            ['B' => 'M2', 'C' => 'M2'],
            ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT, 'C' => ServiceLifetime::TRANSIENT],
            1,
        );
        self::assertTrue(true, 'a null module on either side is exempt');
    }

    /** Cycle payload starts at the cycle entry, not the DFS root (kills UnwrapArraySlice:170). */
    public function testCycleChainStartsAtCycleEntry(): void
    {
        try {
            $this->validator()->validate(
                ['A' => 1, 'B' => 1, 'C' => 1],
                [],
                ['A' => ['B'], 'B' => ['C'], 'C' => ['B']],
                ['A' => 'M', 'B' => 'M', 'C' => 'M'],
                ['A' => ServiceLifetime::TRANSIENT, 'B' => ServiceLifetime::TRANSIENT, 'C' => ServiceLifetime::TRANSIENT],
            );
            self::fail('a B<->C cycle must be detected');
        } catch (ServiceCircularDependencyException $e) {
            self::assertSame(['B', 'C', 'B'], $e->getChain(), 'the chain is sliced from the cycle entry');
        }
    }

    /** A self-cycle yields a two-node chain (kills UnwrapArraySlice:170). */
    public function testSelfCycleChain(): void
    {
        try {
            $this->validator()->validate(
                ['A' => 1],
                [],
                ['A' => ['A']],
                ['A' => 'M'],
                ['A' => ServiceLifetime::TRANSIENT],
            );
            self::fail('a self-cycle must be detected');
        } catch (ServiceCircularDependencyException $e) {
            self::assertSame(['A', 'A'], $e->getChain());
        }
    }
}
