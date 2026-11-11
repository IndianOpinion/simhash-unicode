<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Batch;
use IndianOpinion\Simhash\Bits;
use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\MissingExtensionException;
use IndianOpinion\Simhash\Exception\UnsupportedPlatformException;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Platform;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T49 (B59), T50 (B60), T51 (B61, B62 as decided in ARCHITECTURE.md).
 */
final class PlatformTest extends TestCase
{
    private static function nibblePopcount(int $x): int
    {
        $table = [0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4];
        $n = 0;
        foreach (str_split(sprintf('%016x', $x)) as $nib) {
            $n += $table[(int) hexdec($nib)];
        }

        return $n;
    }

    public function testPopcountMatchesNibbleTable(): void
    {
        mt_srand(64);
        $values = [0, -1, 1, PHP_INT_MIN, PHP_INT_MAX];
        for ($i = 0; $i < 2000; $i++) {
            $values[] = (mt_rand(0, 0x7FFFFFFF) << 33) ^ (mt_rand(0, 0x7FFFFFFF) << 2) ^ mt_rand(0, 3);
        }
        foreach ($values as $v) {
            $expected = self::nibblePopcount($v);
            self::assertSame($expected, Bits::popcount64($v), sprintf('%016x', $v));
            self::assertSame($expected, Bits::popcountBytes(pack('J', $v)));
        }
    }

    public function testForced32BitPathMatches64BitOutput(): void
    {
        $titles = Corpus::titles();
        $s = new Simhash();
        $expected = [];
        foreach ($titles as $id => $row) {
            $fp = $s->hash($row['text']);
            $expected[$id] = [$fp->hex(), $fp->toUnsignedString()];
        }
        $pairs64 = (new Batch($s))->findNearPairs(array_map(static fn (array $r): string => $r['text'], $titles));

        Platform::simulate(is64: false);
        self::assertFalse(Platform::is64Bit());
        $s32 = new Simhash();
        $prev = null;
        foreach ($titles as $id => $row) {
            $fp = $s32->hash($row['text']);
            self::assertSame($expected[$id], [$fp->hex(), $fp->toUnsignedString()], $id);
            self::assertSame($fp->hex(), Fingerprint::fromUnsignedString($fp->toUnsignedString())->hex());
            if ($prev !== null) {
                Platform::reset();
                $d64 = $prev->distanceTo($fp);
                Platform::simulate(is64: false);
                self::assertSame($d64, $prev->distanceTo($fp));
            }
            $prev = $fp;
        }
        $pairs32 = (new Batch($s32))->findNearPairs(array_map(static fn (array $r): string => $r['text'], $titles));
        self::assertEquals($pairs64, $pairs32);
    }

    public function testIntFormRefusedOn32Bit(): void
    {
        $fp = (new Simhash())->hash('Monsoon arrives early');
        Platform::simulate(is64: false);
        try {
            $fp->toInt();
            self::fail('Expected UnsupportedPlatformException');
        } catch (UnsupportedPlatformException $e) {
            self::assertStringContainsString('64-bit', $e->getMessage());
        }
        $this->expectException(UnsupportedPlatformException::class);
        Fingerprint::fromInt(5);
    }

    public function testMissingMbstring(): void
    {
        Platform::simulate(mbstring: false);
        $this->expectException(MissingExtensionException::class);
        new Simhash();
    }

    public function testMissingIntlSkipsNormalisationAndFlagsIt(): void
    {
        $text = Corpus::text('hi-01');
        $withIntl = (new Simhash())->hash($text);

        Platform::simulate(intl: false);
        $s = new Simhash();
        $fp = $s->hash($text);
        self::assertTrue($fp->normalisationUnavailable());
        self::assertContains('normalisation_unavailable', $fp->flags());
        self::assertStringEndsWith('-nonorm', $fp->configId());
        self::assertSame($withIntl->hex(), $fp->hex(), 'already-normal text hashes the same');
        $m = $s->isNearDuplicate($withIntl, $fp);
        self::assertSame(MatchResult::CONFIG_MISMATCH, $m->reason);

        // Fullwidth text is not folded without intl.
        self::assertNotSame(
            $s->tokens('Fullwidth title from a feed'),
            $s->tokens(Corpus::text('sp-12'))
        );

        // Choosing no normalisation explicitly gives a clean id.
        $none = new Simhash(new Config(form: Config::FORM_NONE));
        $fpNone = $none->hash($text);
        self::assertFalse($fpNone->normalisationUnavailable());
        self::assertStringEndsNotWith('-nonorm', $fpNone->configId());
        self::assertNotSame($withIntl->configId(), $fpNone->configId());
    }

    public function testFallbackGraphemesGiveSameHashesForIndic(): void
    {
        $s = new Simhash(new Config(charNgrams: 3));
        $expected = [];
        foreach (Corpus::INDIC as $script) {
            foreach (Corpus::titles($script) as $id => $row) {
                $expected[$id] = $s->hash($row['text'])->hex();
            }
        }
        Platform::simulate(intl: false);
        $fallback = new Simhash(new Config(charNgrams: 3, form: Config::FORM_NONE));
        $intlNone = $expected;
        Platform::reset();
        $withNone = new Simhash(new Config(charNgrams: 3, form: Config::FORM_NONE));
        foreach ($intlNone as $id => $hex) {
            $intlNone[$id] = $withNone->hash(Corpus::text($id))->hex();
        }
        Platform::simulate(intl: false);
        foreach ($intlNone as $id => $hex) {
            self::assertSame($hex, $fallback->hash(Corpus::text($id))->hex(), $id);
        }
    }
}
