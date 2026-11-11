<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Support;

use IndianOpinion\Simhash\Hasher;

/**
 * First 64 bits of MD5, as older PHP simhash code often did. Shipped only
 * as a test and as a README example of the Hasher interface: the package
 * itself has no MD5 mode.
 */
final class Md5Hasher implements Hasher
{
    public function hash(string $feature): string
    {
        return substr(md5($feature, true), 0, 8);
    }

    public function id(): string
    {
        return 'md5-64';
    }
}
