<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T1. The production failure: an ASCII-only tokeniser gives Devanagari,
 * Telugu and Tamil titles zero tokens, so they all hash to zero and sit at
 * distance 0 from each other. Covers B4, B7, B8, B28, B29 and lesson L9.
 */
final class RegressionTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private static function titles(): array
    {
        return [
            'devanagari' => Corpus::text('hi-03'),
            'telugu' => Corpus::text('te-04'),
            'tamil' => Corpus::text('ta-04'),
        ];
    }

    /**
     * The old behaviour, reproduced in a few lines: split on anything that
     * is not a-z or 0-9, then a plain simhash with no empty guard.
     */
    private static function asciiOnlyHash(string $text): string
    {
        $tokens = preg_split('/[^a-z0-9]+/', strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sums = array_fill(0, 64, 0);
        foreach ($tokens as $t) {
            $bits = str_pad(base_convert(hash('xxh64', $t), 16, 2), 64, '0', STR_PAD_LEFT);
            for ($i = 0; $i < 64; $i++) {
                $sums[$i] += $bits[$i] === '1' ? 1 : -1;
            }
        }
        $out = '';
        foreach ($sums as $s) {
            $out .= $s > 0 ? '1' : '0';
        }

        return str_pad(base_convert($out, 2, 16), 16, '0', STR_PAD_LEFT);
    }

    public function testAsciiOnlyTokeniserReproducesTheFailure(): void
    {
        $hashes = array_map([self::class, 'asciiOnlyHash'], self::titles());
        foreach ($hashes as $hex) {
            self::assertSame('0000000000000000', $hex);
        }
        self::assertSame(0, Simhash::hexDistance($hashes['devanagari'], $hashes['telugu']));
        self::assertSame(0, Simhash::hexDistance($hashes['telugu'], $hashes['tamil']));
        self::assertSame(0, Simhash::hexDistance($hashes['devanagari'], $hashes['tamil']));
    }

    public function testUnicodeTokeniserFixesIt(): void
    {
        $s = new Simhash();
        $fps = [];
        foreach (self::titles() as $script => $text) {
            $fp = $s->hash($text);
            self::assertFalse($fp->isEmpty(), $script);
            self::assertGreaterThan(0, $fp->tokenCount(), $script);
            self::assertNotSame('0000000000000000', $fp->hex(), $script);
            $fps[] = $fp;
        }
        for ($i = 0; $i < 3; $i++) {
            for ($j = $i + 1; $j < 3; $j++) {
                $m = $s->isNearDuplicate($fps[$i], $fps[$j]);
                self::assertGreaterThan(6, $m->distance);
                self::assertFalse($m->isNear);
                self::assertSame(MatchResult::FAR, $m->reason);
            }
        }
    }

    public function testEvenEmptyFingerprintsNeverMatch(): void
    {
        $s = new Simhash();
        $a = $s->hash('!!!');
        $b = $s->hash('???');
        self::assertSame(0, $a->distanceTo($b));
        $m = $s->isNearDuplicate($a, $b);
        self::assertFalse($m->isNear);
        self::assertSame(MatchResult::EMPTY, $m->reason);
    }
}
