<?php

/**
 * Hash a few titles and check them pairwise.
 *
 *     php examples/basic.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\Simhash\Simhash;

$simhash = new Simhash();

$titles = [
    'भारत में मानसून समय से पहले पहुँचा, किसान बुवाई की तैयारी में',
    'भारत में मानसून समय से पहले पहुंचा। किसान बुवाई की तैयारी में',
    'విశాఖపట్నంలో కొత్త ఓడరేవు పనులు ప్రారంభం',
    'மதுரையில் புதிய பேருந்து நிலையம் திறப்பு',
    'Kerala floods',
    '!!!',
];

foreach ($titles as $title) {
    $fp = $simhash->hash($title);
    $flags = implode(',', $fp->flags()) ?: '-';
    printf("%s  tokens=%d  flags=%s  %s\n", $fp->hex(), (int) $fp->tokenCount(), $flags, $title);
}

echo "\n";
$count = count($titles);
for ($i = 0; $i < $count; $i++) {
    for ($j = $i + 1; $j < $count; $j++) {
        $m = $simhash->isNearDuplicate($titles[$i], $titles[$j]);
        printf("%d-%d  distance=%2d  %-15s %s\n", $i, $j, $m->distance, $m->reason, $m->isNear ? 'NEAR' : '');
    }
}
