<?php

declare(strict_types=1);

/*
 * ZEF Framework — mutant-killing tests for issue #299.
 * Hotspot: src/Domain/Validation/MessageCatalog.php (13 escaped).
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Validation\MessageCatalog;

/**
 * @internal
 */
final class MessageCatalogMutantKillTest extends TestCase
{
    /** @param array<string,mixed> $templates */
    private function construct(array $templates): MessageCatalog
    {
        $ref = new \ReflectionClass(MessageCatalog::class);
        $obj = $ref->newInstanceWithoutConstructor();
        $ctor = $ref->getConstructor();
        self::assertNotNull($ctor);
        $ctor->invoke($obj, $templates);

        return $obj;
    }

    /** The constructor validates every locale (kills Foreach_:34, LogicalOr:35). */
    public function testConstructorRejectsInvalidLocale(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid catalog locale');
        $this->construct(['BAD LOCALE!' => ['r' => 't']]);
    }

    /** The constructor accepts an uppercase locale (kills PregMatchRemoveFlags:141). */
    public function testConstructorAcceptsUppercaseLocale(): void
    {
        $catalog = $this->construct(['ID_ID' => ['r' => 't']]);
        self::assertInstanceOf(MessageCatalog::class, $catalog);
    }

    /** The constructor validates every rule key (kills Foreach_:38, LogicalOr:39). */
    public function testConstructorRejectsEmptyRuleKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog rule keys must be 1..64 byte strings.');
        $this->construct(['id' => ['' => 't']]);
    }

    public function testConstructorRejectsOverlongRuleKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog rule keys must be 1..64 byte strings.');
        $this->construct(['id' => [str_repeat('r', 65) => 't']]);
    }

    /** The constructor validates every template (kills LogicalOr:42). */
    public function testConstructorRejectsEmptyTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 1..512 bytes');
        $this->construct(['id' => ['r' => '']]);
    }

    public function testConstructorRejectsOverlongTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 1..512 bytes');
        $this->construct(['id' => ['r' => str_repeat('x', 513)]]);
    }

    /** with() validates the rule key (kills Throw_:87). */
    public function testWithRejectsEmptyRuleKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Catalog rule keys must be 1..64 byte strings.');
        MessageCatalog::empty()->with('id', ['' => 't']);
    }

    /** with() validates the template (kills Throw_:90). */
    public function testWithRejectsOverlongTemplate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be 1..512 bytes');
        MessageCatalog::empty()->with('id', ['r' => str_repeat('x', 513)]);
    }

    /** Lookup priority: exact beats base beats wildcard (kills Foreach_/LogicalOr:34/35/38/39/42). */
    public function testLookupPriorityExactBaseWildcard(): void
    {
        $catalog = MessageCatalog::defaultEnglish()
            ->with('id', ['required' => 'base'])
            ->with('id_ID', ['required' => 'exact']);

        self::assertSame('exact', $catalog->templateFor('id_ID', 'required'));
        self::assertSame('base', $catalog->templateFor('id', 'required'));
        self::assertSame('{{label}} is required.', $catalog->templateFor('fr', 'required'));
    }

    /** localeChain is ordered exact -> base -> wildcard (kills UnwrapArrayValues:136). */
    public function testLocaleChainOrder(): void
    {
        self::assertSame(['id_id', 'id', '*'], MessageCatalog::localeChain('id_ID'));
        self::assertSame(['id', '*'], MessageCatalog::localeChain('id'));
        self::assertSame(['*'], MessageCatalog::localeChain('*'));
    }
}
