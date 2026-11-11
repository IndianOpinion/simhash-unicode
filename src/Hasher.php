<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Turns one feature (a token, shingle or n-gram, as UTF-8 bytes) into 64 bits.
 *
 * Implementations must be pure: the same input gives the same output on every
 * host. The output is read big-endian: the first byte holds bits 63 to 56.
 */
interface Hasher
{
    /**
     * Returns exactly 8 raw bytes. Anything else is rejected with a
     * ConfigException the first time the hasher is used.
     */
    public function hash(string $feature): string;

    /**
     * A short stable name for this hasher and its parameters. It becomes part
     * of the configuration id, so two hashers that give different bits must
     * give different ids.
     */
    public function id(): string;
}
