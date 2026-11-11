<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\MatchResult;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\StopWords;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\Md5Hasher;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T44 (B53), T45 (B54), plus the fixture pair file.
 */
final class NearDuplicateTest extends TestCase
{
    public function testThresholdBoundsAndReasons(): void
    {
        $s = new Simhash();
        $a = $s->hash(Corpus::text('en-01'));
        $b = $s->hash(Corpus::text('en-13'));
        $d = $a->distanceTo($b);
        self::assertGreaterThan(0, $d);

        $at = $s->isNearDuplicate($a, $b, $d);
        self::assertTrue($at->isNear);
        self::assertSame(MatchResult::NEAR, $at->reason);
        self::assertSame($d, $at->threshold);

        $below = $s->isNearDuplicate($a, $b, $d - 1);
        self::assertFalse($below->isNear);
        self::assertSame(MatchResult::FAR, $below->reason);

        self::assertTrue($s->isNearDuplicate($a, $a, 0)->isNear);
        self::assertTrue($s->isNearDuplicate($a, $b, 64)->isNear);
        self::assertSame(6, $s->isNearDuplicate($a, $b)->threshold);
    }

    /**
     * @return iterable<string, array{0: int}>
     */
    public static function badThresholds(): iterable
    {
        yield 'negative' => [-1];
        yield 'over 64' => [65];
    }

    /**
     * @dataProvider badThresholds
     */
    public function testInvalidThreshold(int $t): void
    {
        $this->expectException(ConfigException::class);
        (new Simhash())->isNearDuplicate('a b c', 'a b c', $t);
    }

    public function testInvalidThresholdInConfig(): void
    {
        $this->expectException(ConfigException::class);
        new Config(threshold: 65);
    }

    public function testEveryBitAffectingSettingChangesTheId(): void
    {
        $base = new Config();
        $variants = [
            'hasher' => $base->withHasher(new Md5Hasher()),
            'form' => $base->withForm(Config::FORM_NFC),
            'caseFold' => $base->withCaseFold(Config::CASE_SIMPLE),
            'latin' => $base->withFoldLatinDiacritics(true),
            'harakat' => $base->withStripArabicHarakat(true),
            'joiners' => $base->withJoiners(Config::JOINERS_KEEP),
            'apostrophe' => $base->withApostrophe(Config::APOSTROPHE_SPLIT),
            'digits' => $base->withDigits(Config::DIGITS_MASK),
            'urls' => $base->withUrls(Config::URLS_DROP),
            'emoji' => $base->withEmoji(Config::EMOJI_KEEP),
            'stopWords' => $base->withStopWords(StopWords::fromArray(['en' => ['the']])),
            'shingles' => $base->withWordShingles([1, 2]),
            'ngrams' => $base->withCharNgrams(3),
            'auto' => $base->withAutoCharNgrams(Config::AUTO_NGRAMS_OFF),
            'kindWeights' => $base->withKindWeights(1.0, 1.0, 0.5, 1.0),
            'weightMap' => $base->withWeights(['live' => 0.0]),
            'weightCallback' => $base->withWeights(static fn (): float => 1.0, 'one'),
            'maxInputBytes' => $base->withMaxInputBytes(1024),
            'maxFeatures' => $base->withMaxFeatures(64),
        ];
        $ids = [$base->id() => 'base'];
        foreach ($variants as $name => $config) {
            self::assertArrayNotHasKey($config->id(), $ids, $name);
            $ids[$config->id()] = $name;
        }
        // Decision-only settings keep the id.
        self::assertSame($base->id(), $base->withMinTokens(5)->id());
        self::assertSame($base->id(), $base->withThreshold(3)->id());
        self::assertSame($base->id(), $base->withScrub(true)->id());
        self::assertSame($base->id(), $base->withOnLongInput(Config::LONG_INPUT_THROW)->id());
        self::assertSame($base->id(), $base->withBatchIndexCutover(10)->id());
        self::assertSame($base->id(), (new Config())->id(), 'stable across instances');
        self::assertMatchesRegularExpression('/\Ashu1-[0-9a-f]{12}\z/', $base->id());
    }

    public function testConfigMismatch(): void
    {
        $a = (new Simhash())->hash('Monsoon arrives early in Kerala');
        $b = (new Simhash(new Config(digits: Config::DIGITS_MASK)))->hash('Monsoon arrives early in Kerala');
        self::assertSame(0, $a->distanceTo($b), 'raw distance still works');
        $m = (new Simhash())->isNearDuplicate($a, $b);
        self::assertFalse($m->isNear);
        self::assertSame(MatchResult::CONFIG_MISMATCH, $m->reason);

        $parsed = Fingerprint::fromHex($a->hex());
        self::assertSame(MatchResult::CONFIG_MISMATCH, (new Simhash())->isNearDuplicate($parsed, $parsed)->reason);
        $stored = Fingerprint::fromHex($a->hex(), $a->configId(), 5);
        self::assertTrue((new Simhash())->isNearDuplicate($a, $stored)->isNear);
        $noCount = Fingerprint::fromHex($a->hex(), $a->configId());
        self::assertSame(MatchResult::TOO_SHORT, (new Simhash())->isNearDuplicate($a, $noCount)->reason);
    }

    public function testFixturePairs(): void
    {
        $s = new Simhash();
        $near = 0;
        foreach (Corpus::pairs() as $p) {
            $m = $s->isNearDuplicate(Corpus::text($p['a']), Corpus::text($p['b']));
            $label = $p['a'] . ' ' . $p['b'] . ' (' . $p['note'] . ') distance ' . $m->distance;
            if ($p['relation'] === 'near') {
                self::assertTrue($m->isNear, $label);
                $near++;
            } else {
                self::assertFalse($m->isNear, $label);
            }
        }
        self::assertGreaterThan(20, $near);
    }

    public function testUnrelatedFixturesAreFar(): void
    {
        $s = new Simhash();
        $fps = [];
        foreach (Corpus::titles() as $id => $row) {
            $fps[$id] = $s->hash($row['text']);
        }
        // Titles joined by a listed near pair form one family; only pairs
        // across families must be far.
        $family = [];
        foreach (array_keys($fps) as $id) {
            $family[$id] = $id;
        }
        foreach (Corpus::pairs() as $p) {
            if ($p['relation'] === 'near') {
                $from = $family[$p['b']];
                $to = $family[$p['a']];
                foreach ($family as $id => $f) {
                    if ($f === $from) {
                        $family[$id] = $to;
                    }
                }
            }
        }
        $ids = array_keys($fps);
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if ($family[$ids[$i]] === $family[$ids[$j]]) {
                    continue;
                }
                $d = $fps[$ids[$i]]->distanceTo($fps[$ids[$j]]);
                if ($d <= 6) {
                    self::fail('Unlisted near pair ' . $ids[$i] . ' ' . $ids[$j] . ' at ' . $d);
                }
            }
        }
        self::assertGreaterThan(10000, $n * ($n - 1) / 2);
    }
}
