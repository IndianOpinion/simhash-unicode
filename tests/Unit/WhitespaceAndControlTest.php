<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T19 (B19), T20 (B20), T33 (4.1 step 2).
 */
final class WhitespaceAndControlTest extends TestCase
{
    public function testUnicodeSpacesAndPunctuationSeparate(): void
    {
        self::assertSame(['a', 'b'], self::tokens("a\u{00A0}b"));
        self::assertSame(['a', 'b'], self::tokens("a\u{2009}b"));
        self::assertSame(['a', 'b'], self::tokens("a\u{200A}b"));
        self::assertSame(['東京', 'abc'], self::tokens("東京\u{3000}abc"));
        self::assertSame(['भारत', 'मानसून'], self::tokens('भारत।मानसून'));
        self::assertSame(['भारत', 'मानसून'], self::tokens('भारत॥मानसून'));
        self::assertSame(['a', 'b', 'c'], self::tokens('a/b|c'));
    }

    public function testInvisibleCharactersRemovedWithoutSplit(): void
    {
        self::assertSame(['monsoon'], self::tokens("mon\u{00AD}soon"));
        self::assertSame(['monsoon'], self::tokens("mon\u{2060}soon"));
        self::assertSame(['monsoon'], self::tokens("\u{FEFF}mon\u{FEFF}soon"));
    }

    public function testZeroWidthSpaceSplits(): void
    {
        self::assertSame(['mon', 'soon'], self::tokens("mon\u{200B}soon"));
    }

    public function testControlCharactersSeparate(): void
    {
        self::assertSame(['mon', 'soon'], self::tokens("mon\0soon"));
        self::assertSame(['mon', 'soon'], self::tokens("mon\u{0085}soon"));
        self::assertSame(['mon', 'soon'], self::tokens("mon\x07soon"));
        self::assertSame(['mon', 'soon'], self::tokens("mon\tsoon\n"));
    }
}
