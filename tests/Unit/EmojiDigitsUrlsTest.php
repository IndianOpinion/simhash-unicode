<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Tokenizer;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T16 (B16), T17 (B17), T18 (B18).
 */
final class EmojiDigitsUrlsTest extends TestCase
{
    private const FAMILY = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    private const FLAG_IN = "\u{1F1EE}\u{1F1F3}";
    private const KEYCAP_3 = "3\u{FE0F}\u{20E3}";
    private const THUMBS_DARK = "\u{1F44D}\u{1F3FF}";

    public function testEmojiDroppedByDefault(): void
    {
        self::assertSame(['family', 'day'], self::tokens('Family ' . self::FAMILY . ' day'));
        self::assertSame(['india', 'wins'], self::tokens('India ' . self::FLAG_IN . ' wins ' . self::KEYCAP_3));
        self::assertSame(['good', 'news'], self::tokens('good' . self::THUMBS_DARK . 'news'));
        self::assertSame(
            self::tokens(Corpus::text('sp-01')),
            self::tokens('Monsoon arrives early in Kerala')
        );
    }

    public function testEmojiKeptAsWholeSequences(): void
    {
        $keep = new Config(emoji: Config::EMOJI_KEEP);
        self::assertSame(['family', self::FAMILY, 'day'], self::tokens('Family ' . self::FAMILY . ' day', $keep));
        self::assertSame(
            ['india', self::FLAG_IN, 'wins', self::KEYCAP_3],
            self::tokens('India ' . self::FLAG_IN . ' wins ' . self::KEYCAP_3, $keep)
        );
        self::assertSame(['good', self::THUMBS_DARK, 'news'], self::tokens('good' . self::THUMBS_DARK . 'news', $keep));
    }

    public function testEmojiZwjIsNotTheIndicJoinerRule(): void
    {
        // The ZWJ inside the family emoji must not glue the words around it.
        self::assertSame(['a', 'b'], self::tokens('a' . self::FAMILY . 'b'));
    }

    /**
     * @return iterable<string, list<mixed>>
     */
    public static function digitCases(): iterable
    {
        // text, fold, keep, drop, mask
        yield 'devanagari' => ['चुनाव २०२६', ['चुनाव', '2026'], ['चुनाव', '२०२६'], ['चुनाव'], ['चुनाव', '0']];
        yield 'tamil' => ['தேர்தல் ௨௦௨௬', ['தேர்தல்', '2026'], ['தேர்தல்', '௨௦௨௬'], ['தேர்தல்'], ['தேர்தல்', '0']];
        $word = 'انتخابات';
        yield 'arabic-indic' => [$word . ' ٢٠٢٦', [$word, '2026'], [$word, '٢٠٢٦'], [$word], [$word, '0']];
        yield 'ascii' => ['G20 in 2026', ['g20', 'in', '2026'], ['g20', 'in', '2026'], ['g', 'in'], ['g0', 'in', '0']];
    }

    /**
     * @dataProvider digitCases
     * @param list<string> $fold
     * @param list<string> $keep
     * @param list<string> $drop
     * @param list<string> $mask
     */
    public function testDigitModes(string $text, array $fold, array $keep, array $drop, array $mask): void
    {
        self::assertSame($fold, self::tokens($text));
        self::assertSame($keep, self::tokens($text, new Config(digits: Config::DIGITS_KEEP)));
        self::assertSame($drop, self::tokens($text, new Config(digits: Config::DIGITS_DROP)));
        self::assertSame($mask, self::tokens($text, new Config(digits: Config::DIGITS_MASK)));
    }

    public function testFoldedDigitsHashTheSame(): void
    {
        self::assertSame(0, self::distance(Corpus::text('sp-06'), Corpus::text('sp-07')));
        self::assertSame(0, self::distance('चुनाव २०२६ की तारीखें', 'चुनाव 2026 की तारीखें'));
    }

    public function testUrlsBecomeHostByDefault(): void
    {
        $url = 'https://www.example.org/reports/2026/rain';
        self::assertSame(['read', 'at', 'example.org'], self::tokens('Read at ' . $url));
        self::assertSame(['see', 'example.org', 'now'], self::tokens('See http://example.org/x?y=1#z, now'));
        self::assertSame(['see', 'news.example.org'], self::tokens('See www.news.example.org/a/b'));
        self::assertSame(['mail', 'example.org'], self::tokens('Mail desk@example.org'));
        self::assertSame(['see', 'example.org'], self::tokens('See (https://example.org).'));
    }

    public function testUrlsDropped(): void
    {
        $drop = new Config(urls: Config::URLS_DROP);
        self::assertSame(['read', 'at', 'now'], self::tokens('Read at https://www.example.org/x now', $drop));
        self::assertSame(['mail', 'today'], self::tokens('Mail desk@example.org today', $drop));
    }

    public function testUrlsKept(): void
    {
        $keep = new Config(urls: Config::URLS_KEEP);
        self::assertSame(
            ['read', 'https', 'www', 'example', 'org', 'x'],
            self::tokens('Read https://www.example.org/x', $keep)
        );
        self::assertSame(['mail', 'desk', 'example', 'org'], self::tokens('Mail desk@example.org', $keep));
    }

    public function testHostHelper(): void
    {
        self::assertSame('example.org', Tokenizer::host('https://user:pw@www.example.org:8080/p'));
        self::assertSame('example.org', Tokenizer::host('news@example.org'));
        self::assertSame('example.org', Tokenizer::host('www.example.org'));
    }
}
