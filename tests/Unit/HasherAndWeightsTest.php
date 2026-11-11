<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Hasher;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\FixedHasher;
use IndianOpinion\Simhash\Tests\Support\Md5Hasher;
use IndianOpinion\Simhash\Tests\Support\TestCase;
use IndianOpinion\Simhash\Xxh64Hasher;

/**
 * T37 (B39, B42), T38 (B40 via the Hasher interface), T39 (B41), T40 (B44, B45).
 */
final class HasherAndWeightsTest extends TestCase
{
    public function testXxh64PublishedVectors(): void
    {
        $h = new Xxh64Hasher();
        // Reference vectors from the xxHash project, seed 0.
        self::assertSame('ef46db3751d8e999', bin2hex($h->hash('')));
        self::assertSame('d24ec4f1a98c6e5b', bin2hex($h->hash('a')));
        self::assertSame('44bc2cf5ad770999', bin2hex($h->hash('abc')));
        self::assertSame('xxh64', $h->id());
    }

    public function testByteOrderOfASingleFeature(): void
    {
        // One feature with weight 1: every bit of the fingerprint equals the
        // bit of the feature hash, bit 63 first.
        $s = new Simhash(new Config(autoCharNgrams: Config::AUTO_NGRAMS_OFF));
        $fp = $s->hash('abc');
        self::assertSame('44bc2cf5ad770999', $fp->hex());
        self::assertSame('0100', substr($fp->bits(), 0, 4));
    }

    public function testMd5CompatibilityThroughHasherInterface(): void
    {
        $tokens = ['monsoon', 'arrives', 'early', 'in', 'kerala'];
        $sums = array_fill(0, 64, 0);
        foreach ($tokens as $t) {
            $bits = '';
            foreach (str_split(substr(md5($t), 0, 16)) as $nibble) {
                $bits .= str_pad(base_convert($nibble, 16, 2), 4, '0', STR_PAD_LEFT);
            }
            for ($i = 0; $i < 64; $i++) {
                $sums[$i] += $bits[$i] === '1' ? 1 : -1;
            }
        }
        $expected = '';
        for ($i = 0; $i < 64; $i += 4) {
            $n = 0;
            for ($k = 0; $k < 4; $k++) {
                $n = ($n << 1) | ($sums[$i + $k] > 0 ? 1 : 0);
            }
            $expected .= dechex($n);
        }
        $s = new Simhash(new Config(hasher: new Md5Hasher()));
        self::assertSame($expected, $s->hash('Monsoon arrives early in Kerala')->hex());
        self::assertNotSame((new Simhash())->configId(), $s->configId());
    }

    public function testWrongHasherLengthRejectedOnUse(): void
    {
        $bad = new class implements Hasher {
            public function hash(string $feature): string
            {
                return 'short';
            }

            public function id(): string
            {
                return 'bad';
            }
        };
        $s = new Simhash(new Config(hasher: $bad));
        $this->expectException(ConfigException::class);
        $s->hash('Monsoon arrives early');
    }

    public function testWeightMap(): void
    {
        $base = new Simhash();
        $mapped = new Simhash(new Config(weights: ['Live' => 0, 'UPDATES' => 0.0]));
        $a = 'Live updates: monsoon arrives early in Kerala';
        $b = 'Monsoon arrives early in Kerala';
        self::assertGreaterThan(0, $base->distance($base->hash($a), $base->hash($b)));
        self::assertSame(0, $mapped->distance($mapped->hash($a), $mapped->hash($b)));
        self::assertSame(5, $mapped->hash($a)->featureCount());
    }

    public function testWeightCallback(): void
    {
        $calls = [];
        $cb = static function (string $feature, string $kind, int $position) use (&$calls): float {
            $calls[] = [$feature, $kind, $position];

            return $position === 0 ? 3.0 : 1.0;
        };
        $s = new Simhash(new Config(weights: $cb, weightsId: 'first-word-x3'));
        $r = $s->explain('Monsoon arrives early');
        self::assertSame([['monsoon', 'word', 0], ['arrives', 'word', 1], ['early', 'word', 2]], $calls);
        self::assertSame(3.0, $r['features'][0]['weight']);
        self::assertStringContainsString('callback:first-word-x3', $s->config()->describe());
    }

    /**
     * @return iterable<string, array{0: float}>
     */
    public static function badWeights(): iterable
    {
        yield 'negative' => [-1.0];
        yield 'nan' => [NAN];
        yield 'infinite' => [INF];
    }

    /**
     * @dataProvider badWeights
     */
    public function testBadWeightInMapRejected(float $w): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('monsoon');
        new Config(weights: ['monsoon' => $w]);
    }

    /**
     * @dataProvider badWeights
     */
    public function testBadWeightFromCallbackRejected(float $w): void
    {
        $s = new Simhash(new Config(weights: static fn (): float => $w, weightsId: 'bad'));
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('monsoon');
        $s->hash('monsoon arrives early');
    }

    public function testAllZeroWeightsGiveEmpty(): void
    {
        $s = new Simhash(new Config(weights: static fn (): float => 0.0, weightsId: 'zero'));
        $fp = $s->hash('Monsoon arrives early in Kerala');
        self::assertTrue($fp->isEmpty());
        self::assertSame(0, $fp->featureCount());
    }

    public function testFixedHasherIdsDiffer(): void
    {
        self::assertNotSame(
            (new Config(hasher: new FixedHasher(['a' => '12345678'])))->id(),
            (new Config(hasher: new FixedHasher(['a' => '87654321'])))->id()
        );
    }

    public function testCallbackNeedsAName(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage('weightsId');
        new Config(weights: static fn (): float => 1.0);
    }

    public function testCloseWeightsGetDistinctIds(): void
    {
        $a = new Config(wordWeight: 1.0);
        $b = new Config(wordWeight: 1.0 + 1e-9);
        self::assertNotSame($a->id(), $b->id());
        self::assertNotSame(
            (new Config(weights: ['x' => 0.5]))->id(),
            (new Config(weights: ['x' => 0.5 + 1e-9]))->id()
        );
        self::assertSame((new Config(wordWeight: 0.25))->id(), (new Config(wordWeight: 0.25))->id());
    }

    public function testOutputDoesNotDependOnPrecisionSetting(): void
    {
        $weights = ['monsoon' => 0.30000000000000004, 'arrives' => 0.3];
        $s = new Simhash(new Config(weights: $weights));
        $before = $s->explain('monsoon arrives early in kerala');
        $old = ini_get('precision');
        $oldSer = ini_get('serialize_precision');
        ini_set('precision', '3');
        ini_set('serialize_precision', '3');
        try {
            $s2 = new Simhash(new Config(weights: $weights));
            self::assertSame($s->configId(), $s2->configId());
            self::assertSame($before['sums'], $s2->explain('monsoon arrives early in kerala')['sums']);
        } finally {
            ini_set('precision', $old === false ? '14' : $old);
            ini_set('serialize_precision', $oldSer === false ? '-1' : $oldSer);
        }
    }
}
