<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * T2 (B1), T3 (B2), T4 (B3).
 */
final class LatinTest extends TestCase
{
    public function testLatinTokens(): void
    {
        self::assertSame(
            ['monsoon', 'arrives', 'early', 'in', 'kerala'],
            self::tokens('Monsoon arrives early in Kerala')
        );
    }

    public function testDiacriticsKeptByDefault(): void
    {
        self::assertSame(['café', 'são', 'paulo'], self::tokens('Café São Paulo'));
    }

    #[RequiresPhpExtension('intl')]
    public function testDiacriticsFoldedWhenAsked(): void
    {
        $config = new Config(foldLatinDiacritics: true);
        self::assertSame(['cafe', 'sao', 'paulo'], self::tokens('Café São Paulo', $config));
        self::assertSame(['ελλαδα', 'елка'], self::tokens('Ελλάδα ёлка', $config));
    }

    #[RequiresPhpExtension('intl')]
    public function testDiacriticFoldingNeverTouchesIndicMarks(): void
    {
        $config = new Config(foldLatinDiacritics: true);
        self::assertSame(['café', 'मानसून'], self::tokens('Café मानसून'));
        self::assertSame(['cafe', 'मानसून'], self::tokens('Café मानसून', $config));
    }

    public function testApostropheJoinsByDefault(): void
    {
        self::assertSame(['indias', 'monsoon'], self::tokens("India's monsoon"));
        self::assertSame(['indias', 'monsoon'], self::tokens("India\u{2019}s monsoon"));
    }

    public function testApostropheSplitMode(): void
    {
        $config = new Config(apostrophe: Config::APOSTROPHE_SPLIT);
        self::assertSame(['india', 's', 'monsoon'], self::tokens("India's monsoon", $config));
    }

    public function testHyphensAndDashesSeparate(): void
    {
        self::assertSame(['indo', 'pacific'], self::tokens('Indo-Pacific'));
        self::assertSame(['indo', 'pacific'], self::tokens("Indo\u{2013}Pacific"));
        self::assertSame(['indo', 'pacific'], self::tokens("Indo\u{2011}Pacific"));
        self::assertSame(['a', 'b'], self::tokens("a\u{2014}b"));
    }
}
