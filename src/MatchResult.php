<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Answer of a near-duplicate check: yes or no, and why.
 *
 * Count the reasons in your own metrics. A spike in `empty` is the early
 * warning that some script is not being tokenised.
 */
final class MatchResult
{
    public const NEAR = 'near';
    public const FAR = 'far';
    public const EMPTY = 'empty';
    public const TOO_SHORT = 'too_short';
    public const CONFIG_MISMATCH = 'config_mismatch';

    public function __construct(
        public readonly bool $isNear,
        public readonly string $reason,
        public readonly int $distance,
        public readonly int $threshold,
    ) {
    }
}
