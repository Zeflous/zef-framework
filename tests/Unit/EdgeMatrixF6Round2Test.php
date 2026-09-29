<?php

declare(strict_types=1);

/*
 * Edge-Case Matrix fase 6 ronde 2 — pemburu residual (ronde 3).
 *
 * Kurikulum dari diff escape pasca-ronde 1: ServiceDefinition penuh (blok
 * yang tertinggal di ronde 1), truncation dengan karakter PEMBEDA (substr
 * ±1 tak terlihat pada input seragam), crossing tepat scalarByteLength
 * (bool 4/5, int 20, float 24), koalesensi fromArray, cap kondisi bentuk
 * flat, lintas numerik-vs-leksikal pada compare(), pesan eksepsi persis
 * (Concat mutant), kursor racik (min_range/max_range), truncation raw
 * 512/513 SortSpec, ksort radix, dan batas key 128 byte konteks.
 */

namespace Zef\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Zef\Framework\Container\NamespaceRadixTree;
use Zef\Framework\Container\ServiceDefinition;
use Zef\Framework\Container\ServiceLifetime;
use Zef\Framework\CQRS\CqrsContext;
use Zef\Framework\Job\JobContext;
use Zef\Framework\Job\JobEnvelope;
use Zef\Framework\Message\MessageContext;
use Zef\Framework\Observability\CorrelationContext;
use Zef\Framework\Observability\RetryBackoffPolicy;
use Zef\Framework\Resource\Cursor;
use Zef\Framework\Resource\FilterSpec;
use Zef\Framework\Resource\PageRequest;
use Zef\Framework\Resource\SortKey;
use Zef\Framework\Resource\SortSpec;

/**
 * @internal
 */
final class EdgeMatrixF6Round2Test extends TestCase
{
    private const string TID = '4bf92f3577b34da6a3ce929d0e0e4736';
    private const string SID = '00f067aa0ba902b7';

    // ------------------------------------------ ServiceDefinition (tertinggal)

    public function testServiceDefinitionCtorGuardsAndDefaults(): void
    {
        $d = new ServiceDefinition('svc.a', static fn (): null => null);
        self::assertTrue($d->shared, 'Default shared wajib true (singleton).');
        self::assertFalse($d->lazy);
        self::assertSame(ServiceLifetime::SINGLETON, $d->lifetime);
        self::assertSame([], $d->dependencies);
        self::assertSame([], $d->tags);
        self::assertNull($d->module);
        $cases = [
            ['deps non-string', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [42])], // @phpstan-ignore-line — intentionally invalid deps: the constructor must reject them
            ['deps kosong', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [''])],
            ['tag non-string', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [], null, ServiceLifetime::SINGLETON, true, false, [42])],
            ['tag kosong', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [], null, ServiceLifetime::SINGLETON, true, false, [''])],
            ['lifetime asing', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [], null, 'bogus-lifetime')],
            ['shared non-singleton', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', static fn (): null => null, [], null, 'request', true)],
            ['id kosong', static fn (): ServiceDefinition => new ServiceDefinition('', static fn (): null => null)],
            ['factory non-callable', static fn (): ServiceDefinition => new ServiceDefinition('svc.a', 42)],
        ];
        foreach ($cases as [$label, $fn]) {
            try {
                $fn();
                self::fail("{$label} harus ditolak.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        new ServiceDefinition('svc.a', static fn (): null => null, [], null, 'request', false);
    }

    public function testServiceDefinitionFromArrayCoalescence(): void
    {
        $f = static fn (): null => null;
        // lifetime hilang -> SINGLETON; lifetime eksplisit wajib dihormati
        // (membunuh Coalesce yang mengunci SINGLETON).
        self::assertSame(ServiceLifetime::SINGLETON, ServiceDefinition::fromArray('a', ['factory' => $f])->lifetime);
        self::assertSame('request', ServiceDefinition::fromArray('a', ['factory' => $f, 'lifetime' => 'request'])->lifetime);
        // deps/tags kunci non-kontigu -> array_values list ketat.
        $d3 = ServiceDefinition::fromArray('a', ['factory' => $f, 'deps' => [5 => 'x', 9 => 'y'], 'tags' => [3 => 't1', 7 => 't2']]);
        self::assertSame(['x', 'y'], $d3->dependencies);
        self::assertSame(['t1', 't2'], $d3->tags);
        // shared mengikuti lifetime saat hilang; eksplisit menang.
        self::assertFalse(ServiceDefinition::fromArray('a', ['factory' => $f, 'lifetime' => 'request'])->shared);
        self::assertFalse(ServiceDefinition::fromArray('a', ['factory' => $f, 'shared' => false])->shared);
        // lazy default false; '1' -> true.
        self::assertFalse(ServiceDefinition::fromArray('a', ['factory' => $f])->lazy);
        self::assertTrue(ServiceDefinition::fromArray('a', ['factory' => $f, 'lazy' => '1'])->lazy);
    }

    public function testCorrelationScalarCostsExactCrossings(): void
    {
        // true = 4 byte: 4091+5 = 4096 OK (bunuh 4->5); 4092+5 = 4097 throw (bunuh 4->3).
        $this->cost($this->filler(4091), true, false);
        $this->cost($this->filler(4092), true, true);
        // false = 5 byte: 4090+6 = 4096 OK (bunuh 5->6); 4091+6 = 4097 throw (bunuh 5->4).
        $this->cost($this->filler(4090), false, false);
        $this->cost($this->filler(4091), false, true);
        // int = 20 byte: 4075+21 = 4096 OK (bunuh 20->21); 4076+21 = 4097 throw (bunuh 20->19/-20).
        $this->cost($this->filler(4075), 1, false);
        $this->cost($this->filler(4076), 1, true);
        // float = 24 byte: 4071+25 = 4096 OK (bunuh 24->25); 4072+25 = 4097 throw (bunuh 24->23/-24).
        $this->cost($this->filler(4071), 1.5, false);
        $this->cost($this->filler(4072), 1.5, true);
    }

    // ------------------------------------------- Batas key 128 byte konteks

    public function testContextAttributeKeyExactly128IsValid(): void
    {
        $k128 = str_repeat('k', 128);
        new CqrsContext('corr-12345678', null, null, [$k128 => 1]);
        new MessageContext('corr-12345678', null, [$k128 => 1]);
        new JobContext('job-12345678', 1, null, null, [$k128 => 1]);
        self::addToAssertionCount(3);
    }

    public function testCqrsCreateCorrelationIdIs32Hex(): void
    {
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', CqrsContext::create()->correlationId);
    }

    public function testJobContextDeadlineOneMsIsValid(): void
    {
        new JobContext('job-12345678', 1)->withDeadlineMs(1);
        self::addToAssertionCount(1);
    }

    // ------------------------------------------------------- JobEnvelope default

    public function testJobEnvelopePriorityAndAttemptDefaults(): void
    {
        $e = new JobEnvelope('job-12345678', 'mail.send', null, 0);
        self::assertSame(0, $e->priority);
        self::assertSame(1, $e->attempt);
        self::assertSame([], $e->headers);
    }

    // ------------------------------------------------- RetryBackoffPolicy cap 0

    public function testTelemetryBackoffCapZeroStaysZero(): void
    {
        putenv('ZEF_OTEL_RETRY_DELAY_MS=0');
        putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS=0');
        putenv('ZEF_OTEL_RETRY_ATTEMPTS=0');

        try {
            $p = RetryBackoffPolicy::fromEnvironment();
            self::assertSame(0, $p->maxDelayMs, 'Cap env 0 harus tetap 0 (clamp bawah min=0).');
        } finally {
            putenv('ZEF_OTEL_RETRY_DELAY_MS');
            putenv('ZEF_OTEL_RETRY_DELAY_CAP_MS');
            putenv('ZEF_OTEL_RETRY_ATTEMPTS');
        }
    }

    // ------------------------------------------------------ Cursor race kursor

    public function testCursorDecodeMessagesArePrecise(): void
    {
        // trim: spasi penuh -> 'Invalid cursor format.' (bukan encoding).
        foreach (['', '    '] as $blank) {
            try {
                Cursor::decode($blank);
                self::fail('Kursor kosong harus ditolak dengan pesan format.');
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid cursor format.', $e->getMessage(), "Blank '{$blank}' salah pesan.");
            }
        }
        // Checksum mismatch (tamper) tetap pesan checksum.
        $enc = new Cursor(5)->encode('salt');

        try {
            Cursor::decode($enc . 'x', 'salt');
            self::fail('Kursor rusak harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertContains($e->getMessage(), ['Invalid cursor encoding.', 'Cursor checksum mismatch (tampered or stale salt).']);
        }
    }

    public function testCursorOffsetRangeGuardsViaCraftedPayload(): void
    {
        // offset negatif terenkode: real menolak dengan 'Invalid cursor offset.'
        $neg = rtrim(strtr(base64_encode('v1.-1.0'), '+/', '-_'), '=');

        try {
            Cursor::decode($neg);
            self::fail('Offset negatif harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid cursor offset.', $e->getMessage(), 'min_range wajib 0.');
        }
        // offset di atas MAX_OFFSET (2^62): kursor sah dibangun lewat encode
        // (encode tidak membatasi atas), decode harus menolak.
        $big = new Cursor((PHP_INT_MAX >> 2) + 1)->encode('s');

        try {
            Cursor::decode($big, 's');
            self::fail('Offset di atas max_range harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Invalid cursor offset.', $e->getMessage(), 'max_range wajib 2^62.');
        }
        // Tepat 2^62 tetap sah.
        self::assertSame(PHP_INT_MAX >> 2, Cursor::decode(new Cursor(PHP_INT_MAX >> 2)->encode('s'), 's')->offset);
    }

    // ----------------------------------------------------- PageRequest presisi

    public function testPageRequestMessagesAndDefaults(): void
    {
        try {
            new PageRequest(0, 101);
            self::fail('Limit 101 harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Page limit must be <= 100 (hard cap).', $e->getMessage());
        }

        try {
            PageRequest::page(0, 10);
            self::fail('Page 0 harus ditolak.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Page number must be >= 1.', $e->getMessage());
        }
        self::assertSame(0, PageRequest::limit(10)->offset);
        self::assertSame(10, PageRequest::limit(10)->limit);
    }

    public function testPageRequestFromQueryBoundariesOfOneAndZero(): void
    {
        // limit 1 sah (bunuh <= mutant).
        self::assertSame(1, PageRequest::fromQuery(['limit' => '1'])->limit);
        // per_page 1 sah (bunuh > 1 mutant).
        self::assertSame(1, PageRequest::fromQuery(['per_page' => '1'])->limit);
        // per_page 0 -> default (bunuh || mutant yang mengambil 0).
        self::assertSame(20, PageRequest::fromQuery(['limit' => 'x', 'per_page' => '0'])->limit);
        // page 0 -> offset 0 (bunuh || mutant yang menghitung (0-1)*limit).
        $r = PageRequest::fromQuery(['page' => '0', 'limit' => '5']);
        self::assertSame(0, $r->offset);
        self::assertSame(5, $r->limit);
    }

    // ------------------------------------------------------ FilterSpec residual

    public function testFilterSpecFlatCapAndEmptyPartThenValid(): void
    {
        // Cap 32 pada bentuk flat: 33 entri sah -> 32 (bunuh > mutant).
        $query = [];
        for ($i = 0; $i < 33; ++$i) {
            $query['filter_f' . $i] = 'x';
        }
        $wl = array_map(static fn (int $i): string => 'f' . $i, range(0, 32));
        self::assertCount(32, FilterSpec::fromQuery($query, $wl)->conditions());
        // Key 'filter_' (fieldPart kosong) diikuti entri sah -> lanjut, bukan break.
        $spec = FilterSpec::fromQuery(['filter_' => 'x', 'filter_status' => 'open'], $this->wl());
        self::assertCount(1, $spec->conditions());
    }

    public function testFilterSpecFieldBeyond64AndOperatorBoundary(): void
    {
        $f65 = str_repeat('a', 65);
        // Field 65 char yang DIBAIK-whitelist: guard panjang tetap menolak.
        self::assertCount(0, FilterSpec::fromQuery(['filter' => [$f65 => 'x']], [$f65])->conditions());
        // Dengan suffix operator pada field 65: regex real tak boleh span > 64.
        self::assertCount(0, FilterSpec::fromQuery(['filter' => [$f65 . '_gte' => 'x']], [$f65])->conditions());
        // Field 64 + suffix tetap sah.
        $f64 = str_repeat('a', 64);
        $ok = FilterSpec::fromQuery(['filter' => [$f64 . '_gte' => 'x']], [$f64]);
        self::assertCount(1, $ok->conditions());
        self::assertSame('gte', $ok->conditions()[0]->op);
    }

    public function testFilterSpecValueTruncationIsHeadNotShifted(): void
    {
        $v = str_repeat('v', 250) . 'TAIL-abcd'; // 259 byte, ekor embeda
        $spec = FilterSpec::fromQuery(['filter_status' => $v], $this->wl());
        self::assertSame(str_repeat('v', 250) . 'TAIL-a', $spec->conditions()[0]->value, 'Truncation wajib 256 byte pertama.');
    }

    public function testFilterSpecLikeWithNumericAndBoolActuals(): void
    {
        // Actual numerik tetap boleh LIKE (bunuh negasi single sub-expr).
        $num = FilterSpec::fromQuery(['filter_name_like' => '234'], $this->wl());
        self::assertSame([0], array_keys($num->applyTo([['name' => 12345]])));
        // Actual bool TIDAK boleh LIKE (bunuh negasi semua sub-expr).
        $bool = FilterSpec::fromQuery(['filter_flag_like' => '1'], $this->wl());
        self::assertSame([], array_keys($bool->applyTo([['flag' => true]])));
    }

    public function testFilterSpecEqEmptyValueVsNullRow(): void
    {
        // eq '' tidak cocok dengan null (bunuh negasi equal-guard).
        $spec = FilterSpec::fromQuery(['filter_status' => ''], $this->wl());
        $rows = [['status' => null], ['status' => '']];
        self::assertSame([0], array_keys($spec->applyTo($rows)));
    }

    public function testFilterSpecComparePrefersNumericForNumericExpected(): void
    {
        // '10' > '9' secara numerik, tetapi '10' < '9' secara leksikal.
        $gt = FilterSpec::fromQuery(['filter_price_gt' => '9'], $this->wl());
        self::assertSame([0], array_keys($gt->applyTo([['price' => '10']])));
        // Actual non-numerik + expected numerik -> jalur leksikal (bunuh || mutant).
        $gt2 = FilterSpec::fromQuery(['filter_name_gt' => '2'], $this->wl());
        self::assertSame([0], array_keys($gt2->applyTo([['name' => 'b']])));
    }

    // ------------------------------------------------------- SortSpec residual

    public function testSortSpecDefaultDescDefaultsToFalse(): void
    {
        $s = SortSpec::fromQuery([], ['price'], 'sort', ['name']);
        self::assertFalse($s->keys()[0]->desc, 'defaultDesc wajib false tanpa argumen.');
        $c = new SortSpec([], ['name']);
        self::assertFalse($c->keys()[0]->desc);
    }

    public function testSortSpecDefaultsNotAppendedWhenKeysPresent(): void
    {
        // keys sudah ada + defaults tersedia -> defaults TIDAK ditambahkan
        // (bunuh || mutant yang memasuki cabang fallback).
        $s = SortSpec::fromQuery(['sort' => '-price'], ['price', 'name'], 'sort', ['name']);
        self::assertCount(1, $s->keys());
        self::assertSame('price', $s->keys()[0]->field);
    }

    public function testSortSpecRawTruncationBoundaryAt512And513(): void
    {
        $wl = ['price', 'name'];
        // Persis 512: 'price' + 503 koma + 'name' -> name ikut (tanpa trunc).
        $raw512 = 'price' . str_repeat(',', 503) . 'name';
        self::assertSame(512, strlen($raw512));
        self::assertCount(2, SortSpec::fromQuery(['sort' => $raw512], $wl)->keys());
        // 513: dipotong ke 512 -> 'name' terpotong -> 1 key.
        $raw513 = 'price' . str_repeat(',', 504) . 'name';
        self::assertSame(513, strlen($raw513));
        self::assertCount(1, SortSpec::fromQuery(['sort' => $raw513], $wl)->keys());
        // Truncation dari KIRI: '-price' di depan harus selamat.
        $long = '-price' . str_repeat(',', 506) . 'name';
        $s = SortSpec::fromQuery(['sort' => $long], $wl);
        self::assertSame('price', $s->keys()[0]->field);
        self::assertTrue($s->keys()[0]->desc);
    }

    public function testSortSpecOversizeFieldMidListDoesNotAbort(): void
    {
        $f65 = str_repeat('z', 65);
        $s = SortSpec::fromQuery(['sort' => '-price,' . $f65 . ',name'], ['price', 'name']);
        self::assertSame(['price', 'name'], array_map(static fn (SortKey $k): string => $k->field, $s->keys()));
    }

    public function testSortSpecAppliesToExactlyTwoRows(): void
    {
        $s = SortSpec::fromQuery(['sort' => 'group'], ['group']);
        $rows = [['group' => 2, 'tag' => 'a'], ['group' => 1, 'tag' => 'b']];
        self::assertSame(['b', 'a'], array_column($s->applyTo($rows), 'tag'));
    }

    // --------------------------------------------- NamespaceRadixTree residual

    public function testRadixTreeAnnotationsAreKsortedAfterSeal(): void
    {
        $t = new NamespaceRadixTree();
        $t->insert('X\Y');
        $t->annotate('Zed', 'module');
        $t->annotate('Abc', 'internal');
        self::assertSame(['Zed\\', 'Abc\\'], array_keys($t->annotations()), 'Sebelum seal: urutan penyisipan.');
        $t->seal();
        self::assertSame(['Abc\\', 'Zed\\'], array_keys($t->annotations()), 'Seal wajib ksort annotations.');
    }

    public function testRadixTreeChildrenAreKsortedAfterSeal(): void
    {
        $t = new NamespaceRadixTree();
        $t->insert('Bee\X');
        $t->insert('Aye\Y');
        $t->seal();
        $export = $t->exportArray();
        $rootNode = $export['root'] ?? null;
        self::assertIsArray($rootNode);
        $children = $rootNode['children'] ?? null;
        self::assertIsArray($children);
        self::assertSame(['Aye', 'Bee'], array_keys($children), 'Seal wajib ksort children.');
    }

    public function testRadixTreeFromArrayCastsStringScalars(): void
    {
        $loose = NamespaceRadixTree::fromArray([
            'root' => ['ids' => [], 'children' => []],
            'serviceCount' => '7',
            'maxDepth' => '3',
            'rawSegments' => '11',
            'sealed' => 1,
        ]);
        $stats = $loose->stats();
        self::assertSame(7, $stats['serviceIds']);
        self::assertSame(3, $stats['maxDepth']);
        self::assertSame(11, $stats['rawSegments']);
        self::assertTrue($loose->isSealed());
    }

    /** @return list<string> */
    private function wl(): array
    {
        return ['status', 'price', 'name', 'flag'];
    }

    // -------------------------------- CorrelationContext scalarByteLength exact

    /** 12 atribut (64+256) + 1 pengisi -> agregat persis $prior (13 entri).
     *
     * @return array<string, null|bool|float|int|string>
     */
    private function filler(int $prior): array
    {
        $attrs = [];
        for ($i = 0; $i < 12; ++$i) {
            $attrs[str_repeat(chr(97 + $i), 64)] = str_repeat('x', 256); // 12 x 320 = 3840
        }
        $attrs[str_repeat('m', 64)] = str_repeat('y', $prior - 3840 - 64);

        return $attrs;
    }

    /** @param array<string, null|bool|float|int|string> $base */
    private function cost(array $base, bool|float|int|null $value, bool $shouldThrow): void
    {
        $base['n'] = $value; // key 1 char + biaya scalar penghabiran

        try {
            new CorrelationContext(self::TID, self::SID, '01', null, 'op-1', null, $base);
            self::assertFalse($shouldThrow, 'Biaya scalar salah: seharusnya melempar.');
        } catch (\InvalidArgumentException) {
            self::assertTrue($shouldThrow, 'Biaya scalar salah: seharusnya lolos.');
        }
    }
}
