<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\BandIndex;
use IndianOpinion\Simhash\Batch;
use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Exception\InvalidInputException;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\NearPair;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T46 (B55, B58), T47 (B56), T48 (B57).
 */
final class BatchTest extends TestCase
{
    private const CFG = 'test-config';

    /**
     * @param list<NearPair> $pairs
     * @return list<string>
     */
    private static function flat(array $pairs): array
    {
        return array_map(static fn (NearPair $p): string => $p->keyA . '|' . $p->keyB . '|' . $p->distance, $pairs);
    }

    public function testBruteForcePairsOnCorpus(): void
    {
        $batch = new Batch(new Simhash());
        $items = [
            'first' => Corpus::text('en-01'),
            'unrelated' => Corpus::text('en-05'),
            7 => Corpus::text('en-13'),
            'dup' => Corpus::text('en-01'),
            'short' => 'Kerala rain',
            'empty' => '!!!',
            'short2' => 'Kerala rain',
            'hindi' => Corpus::text('hi-01'),
            'hindi2' => Corpus::text('hi-02'),
        ];
        $pairs = $batch->findNearPairs($items);
        self::assertSame(
            ['first|dup|0', 'hindi|hindi2|0', 'first|7|6', '7|dup|6'],
            self::flat($pairs)
        );
    }

    public function testFingerprintsAndTextsMix(): void
    {
        $s = new Simhash();
        $batch = new Batch($s);
        $pairs = $batch->findNearPairs([$s->hash(Corpus::text('bn-02')), Corpus::text('bn-03')]);
        self::assertSame(['0|1|0'], self::flat($pairs));
    }

    public function testDifferentConfigIdsNeverPair(): void
    {
        $a = Fingerprint::fromHex('0123456789abcdef', 'one', 5);
        $b = Fingerprint::fromHex('0123456789abcdef', 'two', 5);
        $c = Fingerprint::fromHex('0123456789abcdee', 'one', 5);
        $pairs = (new Batch(new Simhash()))->findNearPairs(['a' => $a, 'b' => $b, 'c' => $c]);
        self::assertSame(['a|c|1'], self::flat($pairs));
    }

    /**
     * @return array<int, Fingerprint>
     */
    private static function randomCorpus(int $n, int $planted): array
    {
        mt_srand(20261111);
        $hex = [];
        for ($i = 0; $i < $n - $planted; $i++) {
            $hi = mt_rand(0, 0x7FFFFFFF) << 1 | mt_rand(0, 1);
            $lo = mt_rand(0, 0x7FFFFFFF) << 1 | mt_rand(0, 1);
            $hex[] = sprintf('%08x%08x', $hi, $lo);
        }
        for ($i = 0; $i < $planted; $i++) {
            $base = $hex[mt_rand(0, count($hex) - 1)];
            $bits = str_split(str_pad(base_convert(substr($base, 0, 8), 16, 2), 32, '0', STR_PAD_LEFT)
                . str_pad(base_convert(substr($base, 8), 16, 2), 32, '0', STR_PAD_LEFT));
            $flips = $i % 11;
            for ($k = 0; $k < $flips; $k++) {
                $pos = mt_rand(0, 63);
                $bits[$pos] = $bits[$pos] === '1' ? '0' : '1';
            }
            $b = implode('', $bits);
            $hex[] = str_pad(base_convert(substr($b, 0, 32), 2, 16), 8, '0', STR_PAD_LEFT)
                . str_pad(base_convert(substr($b, 32), 2, 16), 8, '0', STR_PAD_LEFT);
        }
        $out = [];
        foreach ($hex as $i => $h) {
            $out[$i] = Fingerprint::fromHex($h, self::CFG, 10);
        }

        return $out;
    }

    public function testBandIndexAgainstBruteForce(): void
    {
        $items = self::randomCorpus(5000, 600);
        $batch = new Batch(new Simhash());
        $brute10 = self::flat($batch->findNearPairs($items, 10, Batch::METHOD_BRUTE));
        self::assertGreaterThan(400, count($brute10));

        foreach ([0, 3, 6, 10] as $t) {
            $brute = array_values(array_filter(
                $brute10,
                static fn (string $p): bool => (int) substr($p, (int) strrpos($p, '|') + 1) <= $t
            ));
            $index = self::flat($batch->findNearPairs($items, $t, Batch::METHOD_INDEX));
            if ($t <= BandIndex::EXACT_UP_TO) {
                self::assertSame($brute, $index, 'exact at threshold ' . $t);
            } else {
                // Candidate filter: never a wrong pair, may miss some.
                self::assertSame([], array_diff($index, $brute), 'no false positives at ' . $t);
                self::assertGreaterThan(0, count($index));
                $exact = array_values(array_filter(
                    $brute,
                    static fn (string $p): bool => (int) substr($p, (int) strrpos($p, '|') + 1) <= 3
                ));
                self::assertSame([], array_diff($exact, $index), 'pairs up to 3 always found');
            }
        }
    }

    public function testAutoUsesIndexOnlyWhenExact(): void
    {
        $items = self::randomCorpus(400, 60);
        $s = new Simhash(new Config(batchIndexCutover: 100));
        $batch = new Batch($s);
        self::assertSame(
            self::flat($batch->findNearPairs($items, 3, Batch::METHOD_BRUTE)),
            self::flat($batch->findNearPairs($items, 3))
        );
        self::assertSame(
            self::flat($batch->findNearPairs($items, 8, Batch::METHOD_BRUTE)),
            self::flat($batch->findNearPairs($items, 8))
        );
    }

    public function testIndexRefusesAbove16(): void
    {
        $this->expectException(ConfigException::class);
        (new Batch(new Simhash()))->findNearPairs([], 17, Batch::METHOD_INDEX);
    }

    public function testUnknownMethod(): void
    {
        $this->expectException(ConfigException::class);
        (new Batch(new Simhash()))->findNearPairs([], 3, 'fast');
    }

    public function testBandIndexQuery(): void
    {
        $index = new BandIndex();
        $index->add('a', Fingerprint::fromHex('0000000000000000'));
        $index->add('b', Fingerprint::fromHex('0000000000000007'));
        $index->add('c', Fingerprint::fromHex('ffffffffffffffff'));
        self::assertSame(3, $index->count());
        self::assertSame(
            [['key' => 'a', 'distance' => 1], ['key' => 'b', 'distance' => 2]],
            $index->query(Fingerprint::fromHex('0000000000000001'), 3)
        );
        self::assertSame(['a|b|3'], self::flat($index->pairs(3)));
        $this->expectException(InvalidInputException::class);
        $index->add('a', Fingerprint::fromHex('0000000000000000'));
    }

    public function testSingleLinkChains(): void
    {
        // A and B differ by 4 bits, B and C by 4 bits, A and C by 8 bits.
        $items = [
            'A' => Fingerprint::fromHex('0000000000000000', self::CFG, 5),
            'B' => Fingerprint::fromHex('000000000000000f', self::CFG, 5),
            'C' => Fingerprint::fromHex('00000000000000ff', self::CFG, 5),
            'X' => Fingerprint::fromHex('ffffffffffff0000', self::CFG, 5),
        ];
        $batch = new Batch(new Simhash());
        self::assertSame([['A', 'B', 'C']], $batch->groupNearDuplicates($items, 4));
        $complete = $batch->groupNearDuplicates($items, 4, true);
        self::assertSame([['A', 'B']], $complete);
    }

    public function testGroupsOnCorpus(): void
    {
        $batch = new Batch(new Simhash());
        $groups = $batch->groupNearDuplicates([
            'a' => Corpus::text('en-01'),
            'b' => Corpus::text('en-05'),
            'c' => Corpus::text('en-02'),
            'd' => Corpus::text('ta-02'),
            'e' => Corpus::text('ta-03'),
            'f' => Corpus::text('en-13'),
        ]);
        self::assertSame([['a', 'c', 'f'], ['d', 'e']], $groups);
    }
}
