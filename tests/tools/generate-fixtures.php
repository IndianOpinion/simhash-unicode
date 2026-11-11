<?php

/**
 * Regenerates tests/fixtures/golden.json and tests/fixtures/aksara.json.
 *
 * Run only as a deliberate, reviewed act, together with an algorithm
 * version bump in Config::ALGORITHM_VERSION:
 *
 *     php tests/tools/generate-fixtures.php
 *
 * Check the diff against explain() output before committing.
 */

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use IndianOpinion\Simhash\Graphemes;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;

$golden = ['_meta' => ['algorithm' => IndianOpinion\Simhash\Config::ALGORITHM_VERSION]];
foreach (Corpus::goldenConfigs() as $name => $config) {
    $simhash = new Simhash($config);
    $golden['_meta']['config_' . $name] = $simhash->configId();
    foreach (Corpus::titles() as $id => $row) {
        $golden[$id][$name] = $simhash->hash($row['text'])->hex();
    }
}
file_put_contents(
    Corpus::DIR . '/golden.json',
    json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

$aksara = [];
foreach (Corpus::INDIC as $script) {
    foreach (Corpus::titles($script) as $id => $row) {
        foreach (preg_split('/\s+/u', $row['text']) ?: [] as $i => $word) {
            $word = (string) preg_replace('/[^\p{L}\p{M}\p{N}\x{200C}\x{200D}]/u', '', $word);
            if ($word !== '') {
                $aksara[$id . '/' . $i] = ['word' => $word, 'segments' => Graphemes::splitWithoutIntl($word)];
            }
        }
    }
}
file_put_contents(
    Corpus::DIR . '/aksara.json',
    json_encode($aksara, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

echo "golden.json and aksara.json written\n";
