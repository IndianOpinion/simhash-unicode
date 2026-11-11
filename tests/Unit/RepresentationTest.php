<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Exception\InvalidInputException;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T42 (B47 to B51), T43 (B52).
 */
final class RepresentationTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: int, 2: string}>
     */
    public static function values(): iterable
    {
        yield 'zero' => ['0000000000000000', 0, '0'];
        yield 'one' => ['0000000000000001', 1, '1'];
        yield '2^63' => ['8000000000000000', PHP_INT_MIN, '9223372036854775808'];
        yield '2^63 - 1' => ['7fffffffffffffff', PHP_INT_MAX, '9223372036854775807'];
        yield '2^64 - 1' => ['ffffffffffffffff', -1, '18446744073709551615'];
        yield 'mixed' => ['0123456789abcdef', 0x0123456789ABCDEF, '81985529216486895'];
    }

    /**
     * @dataProvider values
     */
    public function testRoundTrips(string $hex, int $int, string $unsigned): void
    {
        $fp = Fingerprint::fromHex($hex);
        self::assertSame($hex, $fp->hex());
        self::assertSame($int, $fp->toInt());
        self::assertSame($unsigned, $fp->toUnsignedString());
        self::assertSame($hex, Fingerprint::fromInt($int)->hex());
        self::assertSame($hex, Fingerprint::fromUnsignedString($unsigned)->hex());
        $viaInt = Fingerprint::fromInt($fp->toInt())->toUnsignedString();
        self::assertSame($hex, Fingerprint::fromUnsignedString($viaInt)->hex());
        $bits = $fp->bits();
        self::assertSame(64, strlen($bits));
        self::assertSame($hex, str_pad(base_convert(substr($bits, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
            . str_pad(base_convert(substr($bits, 32), 2, 16), 8, '0', STR_PAD_LEFT));
    }

    public function testHexAcceptsUpperCase(): void
    {
        self::assertSame('abcdef0123456789', Fingerprint::fromHex('ABCDEF0123456789')->hex());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badHex(): iterable
    {
        yield 'short' => ['abc'];
        yield 'long' => ['0123456789abcdef0'];
        yield 'prefix' => ['0x0123456789abcd'];
        yield 'non-hex' => ['0123456789abcdeg'];
        yield 'space' => [' 123456789abcdef'];
        yield 'empty' => [''];
    }

    /**
     * @dataProvider badHex
     */
    public function testBadHexRejected(string $hex): void
    {
        $this->expectException(InvalidInputException::class);
        Fingerprint::fromHex($hex);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function badUnsigned(): iterable
    {
        yield 'too big' => ['18446744073709551616'];
        yield 'way too big' => ['99999999999999999999'];
        yield 'negative' => ['-1'];
        yield 'leading zero' => ['01'];
        yield 'plus' => ['+1'];
        yield 'float' => ['1.0'];
        yield 'empty' => [''];
        yield 'twenty-one digits' => ['100000000000000000000'];
    }

    /**
     * @dataProvider badUnsigned
     */
    public function testBadUnsignedRejected(string $value): void
    {
        $this->expectException(InvalidInputException::class);
        Fingerprint::fromUnsignedString($value);
    }

    public function testDistance(): void
    {
        $s = new Simhash();
        $a = $s->hash('Monsoon arrives early in Kerala');
        $b = $s->hash('Rail fares to stay unchanged');
        self::assertSame(0, $s->distance($a, $a));
        self::assertSame($s->distance($a, $b), $s->distance($b, $a));
        self::assertSame(64, $s->distance('0000000000000000', 'ffffffffffffffff'));
        self::assertSame(64, $s->distance('0123456789abcdef', 'fedcba9876543210'));
        $d = $s->distance($a, $b);
        self::assertSame($d, $s->distance($a->hex(), $b->toInt()));
        self::assertSame($d, $s->distance($a->toUnsignedString(), $b));
        self::assertSame($d, Simhash::hexDistance($a->hex(), $b->hex()));
        self::assertSame($d, $a->distanceTo($b));
        self::assertSame(1, $s->distance(0, 1));
        self::assertSame(1, $s->distance(PHP_INT_MIN, 0));
    }

    public function testStorageRoundTrip(): void
    {
        $s = new Simhash();
        $fp = $s->hash('Monsoon arrives early in Kerala');
        $stored = json_decode((string) json_encode($fp->toArray()), true);
        self::assertIsArray($stored);
        $back = Fingerprint::fromArray($stored);
        self::assertTrue($fp->equals($back));
        self::assertSame($fp->tokenCount(), $back->tokenCount());
        self::assertTrue($s->isNearDuplicate($fp, $back)->isNear);

        $empty = Fingerprint::fromArray($s->hash('')->toArray());
        self::assertTrue($empty->isEmpty());
        self::assertSame('0000000000000000', (string) $empty);
    }

    public function testStoredRowValidation(): void
    {
        $s = new Simhash();
        $row = $s->hash('')->toArray();
        unset($row['features']);
        $empty = Fingerprint::fromArray($row);
        self::assertTrue($empty->isEmpty(), 'the empty flag alone marks the sentinel');
        self::assertSame(MatchResult::EMPTY, $s->isNearDuplicate($empty, $empty)->reason);

        $this->expectException(InvalidInputException::class);
        Fingerprint::fromArray(['hex' => '0000000000000001', 'config' => 'x', 'tokens' => -1]);
    }
}
