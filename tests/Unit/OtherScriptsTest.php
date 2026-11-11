<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T13 (B13), T14 (B14, B32), T15 (B15).
 */
final class OtherScriptsTest extends TestCase
{
    public function testArabicHarakat(): void
    {
        $with = Corpus::text('ar-05');
        $without = Corpus::text('ar-06');
        self::assertNotSame(self::tokens($with), self::tokens($without));
        $strip = new Config(stripArabicHarakat: true);
        self::assertSame(self::tokens($without, $strip), self::tokens($with, $strip));
        self::assertGreaterThan(6, self::distance($with, $without));
        self::assertSame(0, self::distance($with, $without, $strip));
    }

    public function testTatweelAndBidiMarksRemoved(): void
    {
        self::assertSame(['كتاب', 'جديد'], self::tokens("كتـــاب جديد"));
        self::assertSame(['كتاب', 'جديد'], self::tokens("\u{200F}كتاب\u{200E} \u{061C}جديد"));
        self::assertSame(['abc'], self::tokens("a\u{202B}b\u{2067}c"));
    }

    public function testArabicIndicDigitsFolded(): void
    {
        self::assertContains('2026', self::tokens(Corpus::text('sp-11')));
        self::assertSame(['2026'], self::tokens("\u{06F2}\u{06F0}\u{06F2}\u{06F6}"));
    }

    public function testUrduTokens(): void
    {
        self::assertSame(['لاہور', 'میں', 'شدید', 'بارش'], self::tokens(Corpus::text('ur-01')));
    }

    public function testChineseBecomesBigrams(): void
    {
        self::assertSame(['北京', '京今', '今天', '天下', '下雨', '雨了'], self::tokens('北京今天下雨了'));
        $fp = (new Simhash())->hash('北京今天下雨了');
        self::assertSame(3, $fp->tokenCount(), 'six bigrams count as three tokens');
    }

    public function testOneCharacterEditIsCloserThanUnrelated(): void
    {
        $edit = self::distance(Corpus::text('zh-01'), Corpus::text('zh-13'));
        $unrelated = self::distance(Corpus::text('zh-01'), Corpus::text('zh-03'));
        self::assertLessThan(20, $edit);
        self::assertLessThan($unrelated, $edit);
        self::assertSame(0, self::distance(Corpus::text('zh-01'), Corpus::text('zh-02')));
    }

    public function testJapaneseAndThaiUseBigrams(): void
    {
        self::assertSame(['東京', '京で', 'で大', '大雨'], self::tokens('東京で大雨'));
        self::assertSame(['ラー', 'ーメ', 'メン'], self::tokens('ラーメン'));
        self::assertSame(['สวั', 'วัส', 'สดี'], self::tokens('สวัสดี'));
        self::assertGreaterThanOrEqual(3, (new Simhash())->hash(Corpus::text('th-03'))->tokenCount());
    }

    public function testHangulSplitsOnSpaces(): void
    {
        self::assertSame(['서울에', '올해', '첫', '눈이', '내렸다'], self::tokens(Corpus::text('ko-01')));
    }

    public function testMixedScriptBoundaries(): void
    {
        self::assertSame(['ipl', 'मैच'], self::tokens('IPLमैच'));
        self::assertSame(['g20', 'सम्मेलन'], self::tokens('G20 सम्मेलन'));
        self::assertSame(['covid', '19'], self::tokens('COVID-19'));
        self::assertSame(['apple', '发布', '布新', '新款', 'iphone', '手机'], self::tokens(Corpus::text('mx-09')));
        self::assertSame(['bmw', 'の新', '新型'], array_slice(self::tokens(Corpus::text('mx-08')), 0, 3));
    }

    public function testDigitsTakeTheScriptBeforeThem(): void
    {
        $tokens = (new Simhash())->explain('मैच2026 जीता')['tokens'];
        self::assertSame('मैच2026', $tokens[0]['text']);
        self::assertSame('devanagari', $tokens[0]['script']);
    }
}
