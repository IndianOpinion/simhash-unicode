<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Exception\InvalidInputException;

/**
 * In-memory index of fingerprints by four 16-bit bands.
 *
 * Two fingerprints within distance 3 share at least one band exactly
 * (pigeonhole: three differing bits can touch at most three of four bands),
 * so lookups up to threshold 3 are exact: no false negatives. From 4 to 16
 * the bands are only a candidate filter: every result is verified by Hamming
 * distance, so there are no false positives, but some true pairs are missed.
 * Above 16 the index refuses; use brute force.
 *
 * Use it for streaming deduplication: add what you have seen, query each new
 * item. Nothing is persisted.
 */
final class BandIndex
{
    public const BANDS = 4;
    public const EXACT_UP_TO = 3;
    public const MAX_THRESHOLD = 16;

    /** @var array<string, list<int>> band key => item ordinals */
    private array $buckets = [];

    /** @var list<int|string> */
    private array $keys = [];

    /** @var list<string> */
    private array $bytes = [];

    /** @var array<int|string, true> */
    private array $seen = [];

    public function add(int|string $key, Fingerprint $fingerprint): void
    {
        if (isset($this->seen[$key])) {
            throw new InvalidInputException('Key "' . $key . '" is already in the index.');
        }
        $this->seen[$key] = true;
        $ord = count($this->keys);
        $this->keys[] = $key;
        $bytes = $fingerprint->bytes();
        $this->bytes[] = $bytes;
        foreach (self::bandKeys($bytes) as $bk) {
            $this->buckets[$bk][] = $ord;
        }
    }

    public function count(): int
    {
        return count($this->keys);
    }

    /**
     * Items within $threshold of $fingerprint, closest first, then in the
     * order they were added.
     *
     * @return list<array{key: int|string, distance: int}>
     */
    public function query(Fingerprint $fingerprint, int $threshold): array
    {
        self::checkThreshold($threshold);
        $bytes = $fingerprint->bytes();
        $hits = [];
        foreach (self::bandKeys($bytes) as $bk) {
            foreach ($this->buckets[$bk] ?? [] as $ord) {
                if (isset($hits[$ord])) {
                    continue;
                }
                $d = Bits::distance($bytes, $this->bytes[$ord]);
                $hits[$ord] = $d;
            }
        }
        $out = [];
        foreach ($hits as $ord => $d) {
            if ($d <= $threshold) {
                $out[] = ['ord' => $ord, 'key' => $this->keys[$ord], 'distance' => $d];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['distance'], $a['ord']] <=> [$b['distance'], $b['ord']]);

        return array_map(static fn (array $r): array => ['key' => $r['key'], 'distance' => $r['distance']], $out);
    }

    /**
     * Every pair of indexed items within $threshold, as ordinals in the
     * order added: [ordA, ordB, distance] with ordA < ordB. Unsorted.
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public function pairOrdinals(int $threshold): array
    {
        self::checkThreshold($threshold);
        $checked = [];
        $out = [];
        foreach ($this->buckets as $members) {
            $m = count($members);
            for ($i = 0; $i < $m; $i++) {
                $a = $members[$i];
                for ($j = $i + 1; $j < $m; $j++) {
                    $b = $members[$j];
                    $pk = $a . ':' . $b;
                    if (isset($checked[$pk])) {
                        continue;
                    }
                    $checked[$pk] = true;
                    $d = Bits::distance($this->bytes[$a], $this->bytes[$b]);
                    if ($d <= $threshold) {
                        $out[] = [$a, $b, $d];
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Every pair within $threshold, sorted by distance then insertion order.
     *
     * @return list<NearPair>
     */
    public function pairs(int $threshold): array
    {
        $raw = $this->pairOrdinals($threshold);
        usort($raw, static fn (array $x, array $y): int => [$x[2], $x[0], $x[1]] <=> [$y[2], $y[0], $y[1]]);

        return array_map(fn (array $p): NearPair => new NearPair($this->keys[$p[0]], $this->keys[$p[1]], $p[2]), $raw);
    }

    /**
     * @return list<string>
     */
    private static function bandKeys(string $bytes): array
    {
        $out = [];
        for ($b = 0; $b < self::BANDS; $b++) {
            $out[] = chr($b) . substr($bytes, $b * 2, 2);
        }

        return $out;
    }

    private static function checkThreshold(int $threshold): void
    {
        if ($threshold < 0 || $threshold > self::MAX_THRESHOLD) {
            throw new ConfigException(
                'The band index supports thresholds from 0 to ' . self::MAX_THRESHOLD . ' (exact up to '
                . self::EXACT_UP_TO . '), got ' . $threshold . '. Use brute force above that.'
            );
        }
    }
}
