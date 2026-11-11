<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\InvalidInputException;
use IndianOpinion\Simhash\Graphemes;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\TextNormalizer;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T31 (B33, B34, 4.1 step 3), T32 (4.1 step 1).
 */
final class InputLimitsTest extends TestCase
{
    public function testTruncatesAtGraphemeBoundaryWithFlag(): void
    {
        $word = 'क्षत्रिय ';
        $text = str_repeat($word, 400);
        self::assertGreaterThan(4096, strlen($text));
        $s = new Simhash();
        $r = $s->explain($text);
        self::assertLessThanOrEqual(4096, strlen($r['input']));
        self::assertContains('truncated', $r['flags']);
        // The kept text splits into whole graphemes and is a prefix.
        self::assertTrue(str_starts_with($text, $r['input']));
        $rest = substr($text, strlen($r['input']));
        self::assertDoesNotMatchRegularExpression('/\A[\p{M}\x{200C}\x{200D}]/u', $rest);
        self::assertDoesNotMatchRegularExpression('/[' . Graphemes::VIRAMA . ']\z/u', $r['input']);
    }

    public function testTruncateNeverSplitsMarksOrConjuncts(): void
    {
        $text = 'क्षत्रिय';
        for ($cut = 1; $cut < strlen($text); $cut++) {
            $head = TextNormalizer::truncate($text, $cut);
            if ($head === '') {
                continue;
            }
            self::assertSame(1, preg_match('//u', $head), 'valid UTF-8 at ' . $cut);
            self::assertTrue(str_starts_with($text, $head));
            $graphemes = Graphemes::split($head);
            $full = Graphemes::split($text);
            self::assertSame(array_slice($full, 0, count($graphemes)), $graphemes, 'cut ' . $cut);
        }
    }

    public function testThrowMode(): void
    {
        $s = new Simhash(new Config(onLongInput: Config::LONG_INPUT_THROW, maxInputBytes: 64));
        $s->hash(str_repeat('a', 64));
        $this->expectException(InvalidInputException::class);
        $s->hash(str_repeat('a', 65));
    }

    public function testFeatureCap(): void
    {
        $words = [];
        for ($i = 0; $i < 40; $i++) {
            $words[] = 'word' . chr(97 + $i % 26) . chr(97 + intdiv($i, 26));
        }
        $s = new Simhash(new Config(maxFeatures: 16));
        $fp = $s->hash(implode(' ', $words));
        self::assertSame(16, $fp->featureCount());
        self::assertTrue($fp->isTruncated());
        self::assertSame(40, $fp->tokenCount());
    }

    public function testInvalidUtf8RaisesWithOffset(): void
    {
        try {
            (new Simhash())->hash("abc\xFFdef");
            self::fail('Expected InvalidInputException');
        } catch (InvalidInputException $e) {
            self::assertStringContainsString('offset 3', $e->getMessage());
        }
        try {
            (new Simhash())->hash("मान\xE0\xA4");
            self::fail('Expected InvalidInputException');
        } catch (InvalidInputException $e) {
            self::assertStringContainsString('offset 9', $e->getMessage());
        }
    }

    public function testOverlongAndSurrogatesRejected(): void
    {
        foreach (["\xC0\xAF", "\xED\xA0\x80", "\xF4\x90\x80\x80"] as $bad) {
            self::assertSame(0, TextNormalizer::firstBadOffset($bad));
        }
    }

    public function testScrubModeHashes(): void
    {
        $s = new Simhash(new Config(scrub: true));
        self::assertSame(['abc', 'def'], $s->tokens("abc\xFFdef"));
        self::assertSame(['मान', 'x'], $s->tokens("मान\xE0\xA4 x"));
        self::assertSame("abc\u{FFFD}def", TextNormalizer::scrub("abc\xFFdef"));
    }
}
