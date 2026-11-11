<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\FixedHasher;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T27 (B28), T28 (B29), T29 (B30), T30 (B31, B65).
 */
final class EmptyAndSignalTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string}>
     */
    public static function emptyInputs(): iterable
    {
        yield 'empty string' => [''];
        yield 'spaces' => ["   \t\n\u{00A0}\u{3000}"];
        yield 'punctuation' => ['!?.,;:-()[]{}'];
        yield 'emoji only' => ["\u{1F600}\u{1F1EE}\u{1F1F3} \u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}"];
        yield 'stray marks' => ["\u{093E}\u{094D} \u{0301}"];
        yield 'invisible only' => ["\u{200B}\u{00AD}\u{FEFF}\u{2060}"];
    }

    /**
     * @dataProvider emptyInputs
     */
    public function testEmptyFingerprints(string $text): void
    {
        $fp = (new Simhash())->hash($text);
        self::assertTrue($fp->isEmpty());
        self::assertSame('0000000000000000', $fp->hex());
        self::assertSame(0, $fp->tokenCount());
        self::assertSame(0, $fp->featureCount());
        self::assertContains('empty', $fp->flags());
    }

    public function testEmptyNeverMatches(): void
    {
        $s = new Simhash();
        $a = $s->hash('');
        $b = $s->hash('...');
        self::assertSame(0, $s->distance($a, $b));
        $m = $s->isNearDuplicate($a, $b);
        self::assertFalse($m->isNear);
        self::assertSame(MatchResult::EMPTY, $m->reason);

        $real = $s->hash('Monsoon arrives early in Kerala');
        self::assertSame(MatchResult::EMPTY, $s->isNearDuplicate($a, $real)->reason);
        self::assertSame(MatchResult::EMPTY, $s->isNearDuplicate($real, $a)->reason);
    }

    public function testZeroBitsButNotEmpty(): void
    {
        // Every feature hashes to all zero bits, so every sum is negative.
        $s = new Simhash(new Config(hasher: new FixedHasher([])));
        $fp = $s->hash('Monsoon arrives early in Kerala');
        self::assertSame('0000000000000000', $fp->hex());
        self::assertFalse($fp->isEmpty());
        self::assertSame(5, $fp->tokenCount());
    }

    public function testTwoTokenTitlesNeverNear(): void
    {
        $s = new Simhash();
        $m = $s->isNearDuplicate('Kerala floods', 'Kerala floods');
        self::assertSame(0, $m->distance);
        self::assertFalse($m->isNear);
        self::assertSame(MatchResult::TOO_SHORT, $m->reason);
    }

    public function testThreeTokenNearPairMatches(): void
    {
        $s = new Simhash();
        $m = $s->isNearDuplicate('Kerala floods worsen', 'Kerala floods worsen!');
        self::assertTrue($m->isNear);
        self::assertSame(MatchResult::NEAR, $m->reason);
    }

    public function testMinTokensIsConfigurable(): void
    {
        $s = new Simhash(new Config(minTokens: 2));
        self::assertTrue($s->isNearDuplicate('Kerala floods', 'Kerala floods')->isNear);
        $s = new Simhash(new Config(minTokens: 0));
        self::assertTrue($s->isNearDuplicate('Kerala', 'kerala')->isNear);
    }
}
