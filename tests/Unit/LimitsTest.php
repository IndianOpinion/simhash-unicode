<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\StopWords;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;

/**
 * T55 (B66), T56 (B67, B68): what simhash cannot do, pinned as tests so
 * the README claims stay true.
 */
final class LimitsTest extends TestCase
{
    public function testNumericHeavyTitles(): void
    {
        $a = Corpus::text('sp-04');
        $b = Corpus::text('sp-05');
        $keep = new Config(digits: Config::DIGITS_KEEP);
        $mask = new Config(digits: Config::DIGITS_MASK);
        $dKeep = self::distance($a, $b, $keep);
        self::assertGreaterThan(0, $dKeep, 'numbers count as tokens under keep');
        self::assertLessThan(32, $dKeep, 'but only as a minority');
        self::assertSame(0, self::distance($a, $b, $mask), 'identical on purpose under mask');
    }

    public function testParaphraseIsFar(): void
    {
        $s = new Simhash();
        $m = $s->isNearDuplicate(
            'Monsoon arrives early in Kerala this year',
            'Rains reach the southern state ahead of schedule'
        );
        self::assertFalse($m->isNear);
        self::assertGreaterThan(16, $m->distance);
        // Translation is not detected either.
        self::assertFalse($s->isNearDuplicate(Corpus::text('en-01'), Corpus::text('hi-01'))->isNear);
    }

    public function testBoilerplatePrefixAndItsRemedy(): void
    {
        $a = 'Live updates: city council approves new bus routes';
        $b = 'Live updates: heatwave warning issued for northern plains';
        $plain = self::distance('city council approves new bus routes', 'heatwave warning issued for northern plains');
        $prefixed = self::distance($a, $b);
        self::assertLessThan($plain, $prefixed, 'shared prefix pulls unrelated titles closer');

        $stop = new Config(stopWords: StopWords::fromArray(['en' => ['live', 'updates']]));
        self::assertSame($plain, self::distance($a, $b, $stop), 'stop words undo the pull');
    }
}
