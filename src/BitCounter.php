<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Counts, for each of the 64 bit positions, how many added hashes had that
 * bit set. All hashes in one counter share one weight.
 *
 * On 64-bit builds the counts are kept bit-sliced: plane k holds bit k of
 * every position's count, and adding a hash is a ripple-carry add across
 * the planes (a handful of integer operations instead of 64 bit tests). On
 * 32-bit builds a plain per-bit loop is used. Both give identical integer
 * counts, so the final sums are identical.
 *
 * @internal
 */
final class BitCounter
{
    /** @var array<int, int> count bit planes (64-bit path), plane k holds bit k of each count */
    private array $planes = [];

    /** @var array<int, int> ones per bit position, index 0 is bit 63 (32-bit path) */
    private array $ones = [];

    private int $n = 0;

    public function __construct(private readonly float $weight, private readonly bool $packed)
    {
        if (!$packed) {
            $this->ones = array_fill(0, 64, 0);
        }
    }

    /**
     * @param string $hash exactly 8 bytes
     */
    public function add(string $hash): void
    {
        $this->n++;
        if ($this->packed) {
            $carry = Bits::toInt($hash);
            $k = 0;
            while ($carry !== 0) {
                if (!isset($this->planes[$k])) {
                    $this->planes[$k] = $carry;
                    return;
                }
                $plane = $this->planes[$k];
                $this->planes[$k] = $plane ^ $carry;
                $carry &= $plane;
                $k++;
            }

            return;
        }
        $lanes = Bits::lanes($hash);
        for ($lane = 0; $lane < 4; $lane++) {
            $v = $lanes[$lane];
            $base = $lane << 4;
            for ($bit = 0; $bit < 16; $bit++) {
                if ((($v >> (15 - $bit)) & 1) === 1) {
                    $this->ones[$base + $bit]++;
                }
            }
        }
    }

    /**
     * Adds weight * (ones - zeros) for every bit position to $sums.
     *
     * @param array<int, float> $sums
     */
    public function addTo(array &$sums): void
    {
        $ones = $this->packed ? $this->unpackCounts() : $this->ones;
        for ($i = 0; $i < 64; $i++) {
            $sums[$i] += $this->weight * (2 * $ones[$i] - $this->n);
        }
    }

    /**
     * The 64 bits where more than half of the added hashes had a 1, as a
     * native integer (bit 63 first). Equivalent to "weighted sum above
     * zero" when this counter is the only one. 64-bit path only.
     */
    public function majority(): int
    {
        $m = intdiv($this->n, 2);
        $gt = 0;
        $eq = -1;
        for ($k = count($this->planes) - 1; $k >= 0; $k--) {
            $p = $this->planes[$k];
            if ((($m >> $k) & 1) === 1) {
                $eq &= $p;
            } else {
                $gt |= $eq & $p;
                $eq &= ~$p;
            }
        }
        // Count bits above the highest plane are all 0, so a 1 in $m there
        // means no position can exceed it.
        if (($m >> count($this->planes)) !== 0) {
            return 0;
        }

        return $gt;
    }

    public function isPacked(): bool
    {
        return $this->packed;
    }

    public function weight(): float
    {
        return $this->weight;
    }

    /**
     * @return array<int, int>
     */
    private function unpackCounts(): array
    {
        $ones = array_fill(0, 64, 0);
        foreach ($this->planes as $k => $plane) {
            if ($plane === 0) {
                continue;
            }
            for ($i = 0; $i < 64; $i++) {
                $ones[$i] += (($plane >> (63 - $i)) & 1) << $k;
            }
        }

        return $ones;
    }
}
