<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Unit;

use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\Graphemes;
use IndianOpinion\Simhash\Platform;
use IndianOpinion\Simhash\Simhash;
use IndianOpinion\Simhash\Tests\Support\Corpus;
use IndianOpinion\Simhash\Tests\Support\TestCase;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;

/**
 * T52 (B63, B21), T53 (B64), T54 (section 10, locale).
 */
final class DeterminismTest extends TestCase
{
    public function testAksaraSegmentsMatchStoredExpectations(): void
    {
        $stored = Corpus::json('aksara.json');
        self::assertGreaterThan(400, count($stored));
        foreach ($stored as $key => $row) {
            self::assertIsArray($row);
            $word = $row['word'];
            self::assertIsString($word);
            self::assertSame($row['segments'], Graphemes::splitWithoutIntl($word), 'fallback ' . $key);
            self::assertSame($row['segments'], Graphemes::split($word), 'loaded path ' . $key);
        }
    }

    #[RequiresPhpExtension('intl')]
    public function testGoldenCorpus(): void
    {
        $golden = Corpus::json('golden.json');
        $meta = $golden['_meta'];
        self::assertIsArray($meta);
        $checked = 0;
        foreach (Corpus::goldenConfigs() as $name => $config) {
            $s = new Simhash($config);
            self::assertSame($meta['config_' . $name], $s->configId(), 'config id for ' . $name);
            foreach (Corpus::titles() as $id => $row) {
                self::assertArrayHasKey($id, $golden, 'golden entry for ' . $id);
                $entry = $golden[$id];
                self::assertIsArray($entry);
                self::assertSame($entry[$name], $s->hash($row['text'])->hex(), $name . ' ' . $id);
                $checked++;
            }
        }
        self::assertGreaterThan(600, $checked);
    }

    public function testCorpusSize(): void
    {
        $per = [];
        foreach (Corpus::titles() as $row) {
            $per[$row['script']] = ($per[$row['script']] ?? 0) + 1;
        }
        foreach ($per as $script => $n) {
            self::assertGreaterThanOrEqual(12, $n, $script);
        }
        self::assertGreaterThanOrEqual(16, count($per));
    }

    public function testLocaleDoesNotChangeOutput(): void
    {
        $s = new Simhash();
        $titles = ['ISTANBUL istanbul İstanbul', Corpus::text('en-03'), Corpus::text('hi-01'), 'Ünïcödé 3,5 Mio.'];
        $before = array_map(static fn (string $t): string => $s->hash($t)->hex(), $titles);
        $old = setlocale(LC_ALL, '0');
        try {
            foreach (['tr_TR.UTF-8', 'tr_TR', 'de_DE.UTF-8', 'C'] as $locale) {
                setlocale(LC_ALL, $locale);
                $fresh = new Simhash();
                foreach ($titles as $i => $t) {
                    self::assertSame($before[$i], $fresh->hash($t)->hex(), $locale . ' ' . $t);
                }
            }
        } finally {
            setlocale(LC_ALL, $old === false ? 'C' : $old);
        }
    }

    /**
     * With form none the configuration id is the same with and without intl,
     * so the two grapheme paths must give the same bits on every fixture.
     */
    public function testGraphemePathsAgreeOnWholeCorpus(): void
    {
        $configs = [
            new Config(form: Config::FORM_NONE),
            new Config(form: Config::FORM_NONE, charNgrams: 2, autoCharNgrams: Config::AUTO_NGRAMS_ON),
        ];
        foreach ($configs as $config) {
            $icu = [];
            foreach (Corpus::titles() as $id => $row) {
                $icu[$id] = (new Simhash($config))->hash($row['text'])->hex();
            }
            Platform::simulate(intl: false);
            $fallback = new Simhash($config);
            foreach (Corpus::titles() as $id => $row) {
                self::assertSame($icu[$id], $fallback->hash($row['text'])->hex(), $id);
            }
            Platform::reset();
        }
    }

    /**
     * Letters that Unicode's grapheme rules treat as marks or prefixes.
     */
    public function testFallbackFollowsGraphemeRulesForLetterMarks(): void
    {
        $cases = [
            'thai sara am' => ["\u{0E19}\u{0E49}\u{0E33}\u{0E2B}", ["\u{0E19}\u{0E49}\u{0E33}", "\u{0E2B}"]],
            'lao sara am' => ["\u{0E99}\u{0EB3}", ["\u{0E99}\u{0EB3}"]],
            'halfwidth sound mark' => ["\u{FF76}\u{FF9E}\u{FF77}", ["\u{FF76}\u{FF9E}", "\u{FF77}"]],
            'malayalam dot reph' => ["\u{0D4E}\u{0D2E}", ["\u{0D4E}\u{0D2E}"]],
            'hangul jamo' => [
                "\u{1100}\u{1161}\u{11A8}\u{1100}\u{1161}",
                ["\u{1100}\u{1161}\u{11A8}", "\u{1100}\u{1161}"],
            ],
        ];
        foreach ($cases as $name => [$text, $expected]) {
            self::assertSame($expected, Graphemes::splitWithoutIntl($text), 'fallback ' . $name);
            if (Platform::hasIntl()) {
                self::assertSame($expected, Graphemes::split($text), 'intl ' . $name);
            }
        }
    }
}
