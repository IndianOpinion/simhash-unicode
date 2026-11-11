<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Graphemes;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T5 to T12 and T21: Indic scripts, marks, chillu, grapheme n-grams.
 */
final class IndicScriptsTest extends TestCase
{
    private const VIRAMA = '/[' . Graphemes::JOINING_VIRAMA . ']\z/u';

    public function testDevanagariMarksStayInside(): void
    {
        self::assertSame(['भारत', 'में', 'मानसून'], self::tokens('भारत में मानसून'));
        foreach (Corpus::titles('devanagari') as $row) {
            $tokens = self::tokens($row['text']);
            $words = preg_split('/[\s,।:]+/u', $row['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
            self::assertSame(count($words), count($tokens), $row['id']);
            foreach ($tokens as $t) {
                self::assertDoesNotMatchRegularExpression('/\A\p{M}/u', $t, $row['id']);
            }
        }
    }

    public function testNuktaFormsAreEqualUnderNormalisation(): void
    {
        $precomposed = "\u{095C}"; // DEVANAGARI LETTER DDDHA
        $decomposed = "\u{0921}\u{093C}"; // DDA + NUKTA
        $a = "पहा{$precomposed}ी इलाकों में बर्फबारी";
        $b = "पहा{$decomposed}ी इलाकों में बर्फबारी";
        self::assertSame(0, self::distance($a, $b));
        self::assertSame(0, self::distance($a, $b, new Config(form: Config::FORM_NFC)));
        // Documented difference: with no normalisation the code points differ.
        $none = new Config(form: Config::FORM_NONE);
        self::assertNotSame(self::tokens($a, $none), self::tokens($b, $none));
    }

    public function testBengaliWithKhandaTa(): void
    {
        self::assertCount(2, self::tokens('বাংলাদেশে বন্যা'));
        $tokens = self::tokens(Corpus::text('bn-04'));
        self::assertSame('উৎসবের', $tokens[0]);
        self::assertStringContainsString("\u{09CE}", $tokens[0]);
        self::assertCount(5, $tokens);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function wordCounts(): iterable
    {
        yield 'telugu' => [Corpus::text('te-02'), 5];
        yield 'telugu with ZWNJ' => [Corpus::text('te-01'), 3];
        yield 'tamil' => [Corpus::text('ta-04'), 5];
        yield 'kannada' => [Corpus::text('kn-04'), 4];
        yield 'malayalam' => [Corpus::text('ml-07'), 4];
        yield 'gujarati' => [Corpus::text('gu-02'), 6];
        yield 'gurmukhi' => [Corpus::text('pa-04'), 6];
        yield 'odia' => [Corpus::text('or-04'), 4];
    }

    /**
     * @dataProvider wordCounts
     */
    public function testTokenCountEqualsWordCount(string $text, int $words): void
    {
        $fp = (new Simhash())->hash($text);
        self::assertSame($words, $fp->tokenCount());
        self::assertCount($words, self::tokens($text));
    }

    public function testTeluguMarksKept(): void
    {
        self::assertSame(['రైతులకు', 'కొత్త', 'సాగునీటి', 'పథకం', 'ప్రకటన'], self::tokens(Corpus::text('te-02')));
    }

    public function testTamilPulliKeptAndDigitsFolded(): void
    {
        $tokens = self::tokens(Corpus::text('ta-12'));
        self::assertSame('2026', $tokens[0]);
        self::assertContains('தேர்தல்', $tokens);
        // Tamil number sign for ten is a number, kept under fold, dropped under drop.
        self::assertSame(['௰', 'ஆண்டு'], self::tokens('௰ ஆண்டு'));
        self::assertSame(['ஆண்டு'], self::tokens('௰ ஆண்டு', new Config(digits: Config::DIGITS_DROP)));
    }

    public function testKannadaMarksKept(): void
    {
        self::assertSame(['ಬೆಂಗಳೂರಿನಲ್ಲಿ', 'ಭಾರಿ', 'ಮಳೆ'], self::tokens(Corpus::text('kn-01')));
    }

    public function testMalayalamChilluForms(): void
    {
        $atomic = Corpus::text('ml-01');
        $zwj = Corpus::text('ml-02');
        self::assertSame(self::tokens($atomic), self::tokens($zwj));
        self::assertSame(0, self::distance($atomic, $zwj));

        $keep = new Config(joiners: Config::JOINERS_KEEP);
        $a = self::tokens($atomic, $keep);
        $b = self::tokens($zwj, $keep);
        self::assertNotSame($a, $b);
        self::assertCount(3, $b, 'ZWJ never splits the word');
        self::assertStringContainsString("\u{200D}", $b[0]);
    }

    public function testEveryIndicFixtureHasTokens(): void
    {
        $s = new Simhash();
        foreach (Corpus::INDIC as $script) {
            foreach (Corpus::titles($script) as $row) {
                self::assertGreaterThan(0, $s->hash($row['text'])->tokenCount(), $row['id']);
            }
        }
    }

    public function testNgramsNeverStartWithMarkOrEndAfterVirama(): void
    {
        $checked = 0;
        foreach (Corpus::INDIC as $script) {
            foreach (Corpus::titles($script) as $row) {
                foreach (self::tokens($row['text']) as $token) {
                    foreach ([2, 3, 4, 5] as $n) {
                        foreach (Simhash::ngrams($token, $n) as $gram) {
                            $inner = (string) preg_replace('/\A<|>\z/u', '', $gram);
                            self::assertDoesNotMatchRegularExpression('/\A\p{M}/u', $inner, $row['id'] . ' ' . $gram);
                            if (preg_match(self::VIRAMA, $inner) === 1) {
                                // Only a word-final virama may end an n-gram:
                                // a virama before a consonant is merged into
                                // one aksara with it.
                                self::assertStringEndsWith($inner, $token, $row['id'] . ' ' . $gram);
                            }
                            $checked++;
                        }
                    }
                }
            }
        }
        self::assertGreaterThan(1000, $checked);
    }

    public function testConjunctIsOneGrapheme(): void
    {
        self::assertSame(['क्ष', 'त्रि', 'य'], Graphemes::split('क्षत्रिय'));
        self::assertSame(['क्ष', 'त्रि', 'य'], Graphemes::splitWithoutIntl('क्षत्रिय'));
        self::assertSame(['<क्ष', 'क्षत्रि', 'त्रिय', 'य>'], Simhash::ngrams('क्षत्रिय', 2));
    }
}
