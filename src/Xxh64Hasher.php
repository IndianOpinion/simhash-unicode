<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * XXH64 with seed 0, from PHP's built-in hash extension (PHP 8.1 or later).
 *
 * Built in, fast, well mixed in all 64 bits, and specified with published
 * test vectors. Not cryptographic, and it does not need to be: the input is
 * public text. PHP returns the canonical (big-endian) byte order.
 */
final class Xxh64Hasher implements Hasher
{
    public function hash(string $feature): string
    {
        return hash('xxh64', $feature, true);
    }

    public function id(): string
    {
        return 'xxh64';
    }
}
