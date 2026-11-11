<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\ConfigException;

/**
 * Near pairs and groups over a list of texts or fingerprints.
 *
 * Empty items, items below minTokens, and items whose configuration id
 * differs never pair. Keys of the input array are preserved in the output.
 */
final class Batch
{
    public const METHOD_AUTO = 'auto';
    public const METHOD_BRUTE = 'brute';
    public const METHOD_INDEX = 'index';

    public function __construct(private readonly Simhash $simhash)
    {
    }

    /**
     * Every pair within $threshold, sorted by distance then input order.
     *
     * Methods:
     * - brute: compares every pair. Exact for any threshold. O(n^2).
     * - index: four 16-bit bands. Exact up to 3; from 4 to 16 a candidate
     *   filter that can miss pairs (never adds a wrong one); refused above 16.
     * - auto (default): index when the list has at least batchIndexCutover
     *   items and the threshold is 3 or less, brute force otherwise. Auto is
     *   always exact.
     *
     * @param array<int|string, string|Fingerprint> $items
     * @return list<NearPair>
     */
    public function findNearPairs(array $items, ?int $threshold = null, string $method = self::METHOD_AUTO): array
    {
        $threshold = $this->threshold($threshold);
        [$keys, $groups] = $this->prepare($items);
        $useIndex = $this->useIndex($method, $threshold, count($keys));

        $raw = [];
        foreach ($groups as $members) {
            $found = $useIndex ? self::indexPairs($members, $threshold) : self::brutePairs($members, $threshold);
            foreach ($found as $p) {
                $raw[] = $p;
            }
        }
        usort($raw, static fn (array $x, array $y): int => [$x[2], $x[0], $x[1]] <=> [$y[2], $y[0], $y[1]]);

        return array_map(static fn (array $p): NearPair => new NearPair($keys[$p[0]], $keys[$p[1]], $p[2]), $raw);
    }

    /**
     * Groups of two or more keys joined by near pairs, in input order.
     *
     * Single link by default: A near B and B near C puts A and C together
     * even when A and C are far apart. With $maxGroupDiameter, a join is
     * refused when it would put any two members further apart than the
     * threshold (complete link; slower).
     *
     * @param array<int|string, string|Fingerprint> $items
     * @return list<list<int|string>>
     */
    public function groupNearDuplicates(
        array $items,
        ?int $threshold = null,
        bool $maxGroupDiameter = false,
        string $method = self::METHOD_AUTO,
    ): array {
        $threshold = $this->threshold($threshold);
        $keys = array_keys($items);
        $ordOf = array_flip($keys);
        $fps = [];
        $bytes = [];
        foreach ($items as $k => $item) {
            $fp = $item instanceof Fingerprint ? $item : $this->simhash->hash($item);
            $fps[$k] = $fp;
            $bytes[$ordOf[$k]] = $fp->bytes();
        }
        $pairs = $this->findNearPairs($fps, $threshold, $method);

        $parent = [];
        $members = [];
        $find = static function (int $x) use (&$parent): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        foreach ($pairs as $p) {
            foreach ([$ordOf[$p->keyA], $ordOf[$p->keyB]] as $o) {
                if (!isset($parent[$o])) {
                    $parent[$o] = $o;
                    $members[$o] = [$o];
                }
            }
            $ra = $find($ordOf[$p->keyA]);
            $rb = $find($ordOf[$p->keyB]);
            if ($ra === $rb) {
                continue;
            }
            if ($maxGroupDiameter) {
                foreach ($members[$ra] as $x) {
                    foreach ($members[$rb] as $y) {
                        if (Bits::distance($bytes[$x], $bytes[$y]) > $threshold) {
                            continue 3;
                        }
                    }
                }
            }
            [$root, $child] = $ra < $rb ? [$ra, $rb] : [$rb, $ra];
            $parent[$child] = $root;
            $members[$root] = array_merge($members[$root], $members[$child]);
            unset($members[$child]);
        }

        $groups = [];
        foreach ($members as $root => $list) {
            if ($find($root) !== $root || count($list) < 2) {
                continue;
            }
            sort($list);
            $groups[$list[0]] = array_map(static fn (int $o): int|string => $keys[$o], $list);
        }
        ksort($groups);

        return array_values($groups);
    }

    private function threshold(?int $threshold): int
    {
        $threshold ??= $this->simhash->config()->threshold;
        if ($threshold < 0 || $threshold > 64) {
            throw new ConfigException('threshold must be from 0 to 64, got ' . $threshold . '.');
        }

        return $threshold;
    }

    private function useIndex(string $method, int $threshold, int $count): bool
    {
        switch ($method) {
            case self::METHOD_BRUTE:
                return false;
            case self::METHOD_INDEX:
                if ($threshold > BandIndex::MAX_THRESHOLD) {
                    throw new ConfigException(
                        'The band index supports thresholds up to ' . BandIndex::MAX_THRESHOLD
                        . '; use brute force for ' . $threshold . '.'
                    );
                }

                return true;
            case self::METHOD_AUTO:
                return $threshold <= BandIndex::EXACT_UP_TO && $count >= $this->simhash->config()->batchIndexCutover;
            default:
                throw new ConfigException('method must be auto, brute or index, got "' . $method . '".');
        }
    }

    /**
     * Hashes texts and sorts eligible items into groups by configuration id.
     *
     * @param array<int|string, string|Fingerprint> $items
     * @return array{0: list<int|string>, 1: array<string, array<int, string>>}
     */
    private function prepare(array $items): array
    {
        $keys = [];
        $groups = [];
        $ord = 0;
        foreach ($items as $key => $item) {
            $keys[] = $key;
            $fp = $item instanceof Fingerprint ? $item : $this->simhash->hash($item);
            if ($fp->configId() !== '' && $this->simhash->hasSignal($fp)) {
                $groups[$fp->configId()][$ord] = $fp->bytes();
            }
            $ord++;
        }

        return [$keys, $groups];
    }

    /**
     * @param array<int, string> $members ordinal => bytes
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function brutePairs(array $members, int $threshold): array
    {
        $ords = array_keys($members);
        $n = count($ords);
        $out = [];
        if (Platform::is64Bit()) {
            $vals = [];
            foreach ($ords as $i => $o) {
                $vals[$i] = Bits::toInt($members[$o]);
            }
            for ($i = 0; $i < $n; $i++) {
                $a = $vals[$i];
                for ($j = $i + 1; $j < $n; $j++) {
                    $x = $a ^ $vals[$j];
                    // Inline popcount of two 32-bit halves; see Bits::popcount64.
                    $h = ($x >> 32) & 0xFFFFFFFF;
                    $l = $x & 0xFFFFFFFF;
                    $h -= ($h >> 1) & 0x55555555;
                    $l -= ($l >> 1) & 0x55555555;
                    $h = ($h & 0x33333333) + (($h >> 2) & 0x33333333);
                    $l = ($l & 0x33333333) + (($l >> 2) & 0x33333333);
                    $h = ($h + ($h >> 4)) & 0x0F0F0F0F;
                    $l = ($l + ($l >> 4)) & 0x0F0F0F0F;
                    $d = ((($h * 0x01010101) & 0xFFFFFFFF) >> 24) + ((($l * 0x01010101) & 0xFFFFFFFF) >> 24);
                    if ($d <= $threshold) {
                        $out[] = [$ords[$i], $ords[$j], $d];
                    }
                }
            }

            return $out;
        }
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $d = Bits::popcountBytes($members[$ords[$i]] ^ $members[$ords[$j]]);
                if ($d <= $threshold) {
                    $out[] = [$ords[$i], $ords[$j], $d];
                }
            }
        }

        return $out;
    }

    /**
     * @param array<int, string> $members ordinal => bytes
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private static function indexPairs(array $members, int $threshold): array
    {
        $index = new BandIndex();
        $ords = array_keys($members);
        foreach ($ords as $o) {
            $index->add($o, Fingerprint::fromHex(bin2hex($members[$o])));
        }
        $out = [];
        foreach ($index->pairOrdinals($threshold) as [$a, $b, $d]) {
            $out[] = [$ords[$a], $ords[$b], $d];
        }

        return $out;
    }
}
