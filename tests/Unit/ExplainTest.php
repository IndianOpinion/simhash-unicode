<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\FixedHasher;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T59 (section 11), plus agreement of the fast and the detailed paths.
 */
final class ExplainTest extends TestCase
{
    public function testReportFields(): void
    {
        $s = new Simhash();
        $text = 'IPL 2026: मुंबई ने फाइनल जीता';
        $r = $s->explain($text);
        $fp = $s->hash($text);
        self::assertSame($text, $r['input']);
        self::assertSame('ipl 2026: मुंबई ने फाइनल जीता', $r['normalised']);
        self::assertSame($fp->hex(), $r['hex']);
        self::assertSame($fp->configId(), $r['configId']);
        self::assertSame($fp->tokenCount(), $r['tokenCount']);
        self::assertSame($fp->featureCount(), $r['featureCount']);
        self::assertSame($fp->flags(), $r['flags']);
        self::assertCount(64, $r['sums']);
        self::assertSame('latin', $r['tokens'][0]['script']);
        self::assertSame('devanagari', $r['tokens'][2]['script']);
        foreach ($r['features'] as $f) {
            self::assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', $f['hash']);
        }
        self::assertArrayHasKey('pcre', $r['environment']);
        self::assertArrayHasKey('intl', $r['environment']);
        self::assertStringContainsString('form=NFKC', $r['config']);

        // The sums reproduce the hex.
        $bits = '';
        foreach ($r['sums'] as $sum) {
            $bits .= $sum > 0 ? '1' : '0';
        }
        self::assertSame($fp->bits(), $bits);
    }

    public function testFastAndDetailedPathsAgree(): void
    {
        $configs = [new Config(), new Config(charNgrams: 3), new Config(wordShingles: [1, 2, 3])];
        foreach ($configs as $config) {
            $s = new Simhash($config);
            foreach (Corpus::titles() as $id => $row) {
                self::assertSame($s->explain($row['text'])['hex'], $s->hash($row['text'])->hex(), $id);
            }
        }
    }

    public function testTieRuleOnBothPaths(): void
    {
        $x = "\xF0\xF0\xF0\xF0\x00\x00\x00\x00";
        $y = "\x0F\x0F\x0F\x0F\x00\x00\x00\x00";
        $s = new Simhash(new Config(
            hasher: new FixedHasher(['x' => $x, 'y' => $y]),
            autoCharNgrams: Config::AUTO_NGRAMS_OFF,
        ));
        $cases = ['x y' => '0000000000000000', 'x x y' => 'f0f0f0f000000000', 'x y y' => '0f0f0f0f00000000'];
        foreach ($cases as $t => $hex) {
            self::assertSame($hex, $s->hash($t)->hex(), $t);
            self::assertSame($hex, $s->explain($t)['hex'], $t);
        }
    }

    public function testManyFeaturesOneWeight(): void
    {
        $words = [];
        for ($i = 0; $i < 300; $i++) {
            $words[] = 'w' . $i;
        }
        $text = implode(' ', $words);
        $s = new Simhash(new Config(maxInputBytes: 8192));
        self::assertSame($s->explain($text)['hex'], $s->hash($text)->hex());
    }
}
