<?php

/**
 * Deduplicate a stream of feed items, then group a batch.
 *
 *     php examples/dedupe-feed.php
 *
 * Shows the two usual shapes: streaming (a BandIndex of what was seen) and
 * batch (Batch::groupNearDuplicates). Fingerprints are stored with their
 * configuration id and token count, as you would in a database.
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use IndianOpinion\Simhash\BandIndex;
use IndianOpinion\Simhash\Batch;
use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Fingerprint;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\StopWords;

// Outlet boilerplate pulls unrelated titles together; strip it.
$config = new Config(stopWords: StopWords::fromArray(['en' => ['live', 'updates', 'breaking']]));
$simhash = new Simhash($config);

$feed = [
    'a1' => 'Live updates: Monsoon arrives early in Kerala, farmers prepare for sowing',
    'b7' => 'Monsoon arrives early in Kerala; farmers prepare for sowing!',
    'c2' => 'City council approves new bus routes for the eastern suburbs',
    'd9' => 'চা বাগানের শ্রমিকদের মজুরি বৃদ্ধি',
    'e4' => 'চা বাগানের শ্রমিকদের মজুরি বৃদ্ধি।',
    'f1' => 'BREAKING: city council approves new bus routes for the eastern suburbs',
];

// Streaming: keep a band index of stored fingerprints. Threshold 3 is exact.
$index = new BandIndex();
$store = [];
foreach ($feed as $id => $title) {
    $fp = $simhash->hash($title);
    $hits = $simhash->hasSignal($fp) ? $index->query($fp, 3) : [];
    if ($hits !== []) {
        printf("%s duplicates %s (distance %d)\n", $id, $hits[0]['key'], $hits[0]['distance']);
        continue;
    }
    $index->add($id, $fp);
    $store[$id] = $fp->toArray();
    printf("%s stored as %s\n", $id, $fp->hex());
}

// Later, from storage: rebuild fingerprints and group everything.
$fingerprints = array_map(static fn (array $row): Fingerprint => Fingerprint::fromArray($row), $store);
$fingerprints += array_map([$simhash, 'hash'], array_diff_key($feed, $store));
$groups = (new Batch($simhash))->groupNearDuplicates($fingerprints);

echo "\nGroups:\n";
foreach ($groups as $group) {
    echo '  ', implode(', ', $group), "\n";
}
