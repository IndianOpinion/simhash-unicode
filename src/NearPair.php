<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Two items of a batch within the threshold. keyA comes before keyB in the
 * input order.
 */
final class NearPair
{
    public function __construct(
        public readonly int|string $keyA,
        public readonly int|string $keyB,
        public readonly int $distance,
    ) {
    }
}
