<?php

/**
 * Rough benchmark for the README performance table.
 *
 *     php bin/benchmark.php            # full run
 *     php bin/benchmark.php --quick    # smaller, for CI smoke
 *
 * Prints one line per measurement. Numbers depend on the machine.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\Simhash\Batch;
use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\TextNormalizer;

$quick = in_array('--quick', $argv, true);
$simhash = new Simhash();

$title = 'Monsoon arrives early in Kerala as farmers prepare for the sowing season';
$hindi = 'भारत में मानसून समय से पहले पहुँचा, किसान बुवाई की तैयारी में जुटे';

/**
 * Median time of $runs calls, in microseconds.
 *
 * @param callable(): mixed $fn
 */
$median = static function (callable $fn, int $runs): float {
    $times = [];
    for ($i = 0; $i < $runs; $i++) {
        $t = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $t) / 1000;
    }
    sort($times);

    return $times[intdiv(count($times), 2)];
};

$runs = $quick ? 200 : 5000;
printf("hash 80-char English title: median %.1f us\n", $median(static fn () => $simhash->hash($title), $runs));
printf("hash Hindi title: median %.1f us\n", $median(static fn () => $simhash->hash($hindi), $runs));

$long = TextNormalizer::truncate(str_repeat($hindi . ' ', 40), 4096);
printf("hash 4096-byte input: median %.0f us\n", $median(static fn () => $simhash->hash($long), $quick ? 20 : 200));

$a = $simhash->hash($title);
$b = $simhash->hash($hindi);
printf("distance of two fingerprints: median %.2f us\n", $median(static fn () => $a->distanceTo($b), $runs));

$n = $quick ? 2000 : 10000;
mt_srand(1);
$items = [];
for ($i = 0; $i < $n; $i++) {
    $items[] = Fingerprint::fromHex(sprintf('%08x%08x', mt_rand(0, 0x7FFFFFFF), mt_rand(0, 0x7FFFFFFF)), 'bench', 10);
}
$batch = new Batch(new Simhash(new Config()));
foreach ([['brute', 6], ['index', 3]] as [$method, $t]) {
    $start = hrtime(true);
    $mem = memory_get_usage();
    $pairs = $batch->findNearPairs($items, $t, $method);
    printf(
        "findNearPairs %d items, %s, threshold %d: %.2f s, %d pairs, %.1f MB\n",
        $n,
        $method,
        $t,
        (hrtime(true) - $start) / 1e9,
        count($pairs),
        (memory_get_peak_usage() - $mem) / 1048576
    );
}
