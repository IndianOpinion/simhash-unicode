<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Support;

use IndianOpinion\Simhash\Hasher;

/**
 * Test hasher that returns preset bytes per feature, or a default.
 */
final class FixedHasher implements Hasher
{
    /**
     * @param array<string, string> $map feature => raw bytes
     */
    public function __construct(private readonly array $map, private readonly string $default = "\0\0\0\0\0\0\0\0")
    {
    }

    public function hash(string $feature): string
    {
        return $this->map[$feature] ?? $this->default;
    }

    public function id(): string
    {
        return 'fixed-' . md5(serialize($this->map) . $this->default);
    }
}
