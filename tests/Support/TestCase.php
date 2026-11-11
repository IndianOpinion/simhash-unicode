<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Support;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Platform;
use IndianOpinion\Simhash\Simhash;
use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        Platform::reset();
        parent::tearDown();
    }

    /**
     * @return list<string>
     */
    protected static function tokens(string $text, ?Config $config = null): array
    {
        return (new Simhash($config))->tokens($text);
    }

    protected static function distance(string $a, string $b, ?Config $config = null): int
    {
        $s = new Simhash($config);

        return $s->distance($s->hash($a), $s->hash($b));
    }
}
