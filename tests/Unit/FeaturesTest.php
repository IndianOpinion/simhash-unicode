<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\FixedHasher;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T34 (B35), T35 (B37), T36 (B38, B43), T41 (B46).
 */
final class FeaturesTest extends TestCase
{
    /**
     * @return list<string>
     */
    private static function featureTexts(Simhash $s, string $text, string $kind): array
    {
        $out = [];
        foreach ($s->explain($text)['features'] as $f) {
            if ($f['kind'] === $kind) {
                $out[] = $f['text'];
            }
        }

        return $out;
    }

    public function testWordBigrams(): void
    {
        $s = new Simhash(new Config(wordShingles: [1, 2]));
        self::assertSame(
            ['monsoon arrives', 'arrives early', 'early in', 'in kerala'],
            self::featureTexts($s, 'Monsoon arrives early in Kerala', Simhash::KIND_SHINGLE)
        );
    }

    public function testShinglesDoNotSpanRemovedUrlOrSentenceEnd(): void
    {
        $s = new Simhash(new Config(wordShingles: [2], urls: Config::URLS_DROP));
        self::assertSame(
            ['heavy rain', 'today in', 'in kerala'],
            self::featureTexts($s, 'Heavy rain https://example.org/x today in Kerala', Simhash::KIND_SHINGLE)
        );
        self::assertSame(
            ['heavy rain', 'more today'],
            self::featureTexts($s, 'Heavy rain. More today', Simhash::KIND_SHINGLE)
        );
        // A dot inside a word or a host is not a sentence end.
        $host = new Simhash(new Config(wordShingles: [2]));
        self::assertSame(
            ['see example.org', 'example.org now'],
            self::featureTexts($host, 'See https://example.org now', Simhash::KIND_SHINGLE)
        );
    }

    public function testExplicitCharNgrams(): void
    {
        $s = new Simhash(new Config(charNgrams: 3));
        self::assertSame(
            ['<ra', 'rai', 'ain', 'in>', '<to', 'tod', 'oda', 'day', 'ay>', '<in>', '<ke'],
            array_slice(self::featureTexts($s, 'rain today in kerala', Simhash::KIND_NGRAM), 0, 11)
        );
    }

    public function testAutoNgramsForShortTitles(): void
    {
        $s = new Simhash();
        $short = 'மடிக்கணினி வழங்கல்';
        $fp = $s->hash($short);
        self::assertTrue($fp->usedAutoNgrams());
        self::assertSame(2, $fp->tokenCount());
        self::assertNotSame([], self::featureTexts($s, $short, Simhash::KIND_NGRAM));
        $m = $s->isNearDuplicate($short, $short);
        self::assertSame(MatchResult::TOO_SHORT, $m->reason, 'auto n-grams do not lower the signal rule');

        self::assertFalse($s->hash('Monsoon arrives early')->usedAutoNgrams());
    }

    public function testAutoNgramsCanBeForcedOnOrOff(): void
    {
        $off = new Simhash(new Config(autoCharNgrams: Config::AUTO_NGRAMS_OFF));
        self::assertFalse($off->hash('மடிக்கணினி வழங்கல்')->usedAutoNgrams());
        $on = new Simhash(new Config(autoCharNgrams: Config::AUTO_NGRAMS_ON));
        self::assertTrue($on->hash('Monsoon arrives early in Kerala')->usedAutoNgrams());
    }

    public function testAutoNgramsStabiliseShortCompounds(): void
    {
        // One-word Tamil title with a changed case ending.
        $with = self::distance('நீர்ப்பாசனத்திட்டம்', 'நீர்ப்பாசனத்திட்டத்தில்');
        $without = self::distance(
            'நீர்ப்பாசனத்திட்டம்',
            'நீர்ப்பாசனத்திட்டத்தில்',
            new Config(autoCharNgrams: Config::AUTO_NGRAMS_OFF)
        );
        self::assertLessThan($without, $with);
    }

    public function testRepeatedTokenCountsMore(): void
    {
        $h = "\xFF\x00\x00\x00\x00\x00\x00\x00";
        $s = new Simhash(new Config(hasher: new FixedHasher(['rain' => $h]), autoCharNgrams: Config::AUTO_NGRAMS_OFF));
        $sums = $s->explain('rain rain rain')['sums'];
        self::assertSame(3.0, $sums[0]);
        self::assertSame(-3.0, $sums[63]);
    }

    public function testNgramWeightDefault(): void
    {
        $s = new Simhash(new Config(charNgrams: 3));
        foreach ($s->explain('rain today in kerala')['features'] as $f) {
            self::assertSame($f['kind'] === Simhash::KIND_NGRAM ? 0.25 : 1.0, $f['weight']);
        }
        // Auto trigrams use weight 1.
        foreach ((new Simhash())->explain('kerala')['features'] as $f) {
            self::assertSame(1.0, $f['weight']);
        }
    }

    public function testTieGivesZero(): void
    {
        $x = "\xF0\xF0\xF0\xF0\x00\x00\x00\x00";
        $y = "\x0F\x0F\x0F\x0F\x00\x00\x00\x00";
        $s = new Simhash(new Config(
            hasher: new FixedHasher(['x' => $x, 'y' => $y]),
            autoCharNgrams: Config::AUTO_NGRAMS_OFF,
        ));
        $r = $s->explain('x y');
        // First 32 bits: one +1 and one -1 per bit, so the sum is 0 and the bit is 0.
        self::assertSame(0.0, $r['sums'][0]);
        self::assertSame('0000000000000000', $r['hex']);
        self::assertFalse($r['empty']);
        $r = $s->explain('x x y');
        self::assertSame('f0f0f0f000000000', $r['hex']);
    }
}
