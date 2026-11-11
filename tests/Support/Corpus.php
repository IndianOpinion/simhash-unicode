<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Tests\Support;

use IndianOpinion\Simhash\Config;
use RuntimeException;

/**
 * Loads the self-written fixture corpus in tests/fixtures.
 */
final class Corpus
{
    public const DIR = __DIR__ . '/../fixtures';

    /** Scripts whose titles are used for Indic checks. */
    public const INDIC = [
        'devanagari', 'bengali', 'telugu', 'tamil', 'kannada', 'malayalam', 'gujarati', 'gurmukhi', 'oriya',
    ];

    /**
     * @return array<string, array{id: string, lang: string, script: string, text: string}>
     */
    public static function titles(?string $script = null): array
    {
        $out = [];
        $files = glob(self::DIR . '/titles/*.jsonl') ?: [];
        sort($files);
        foreach ($files as $file) {
            foreach (self::jsonl($file) as $row) {
                if (!isset($row['id'], $row['lang'], $row['script'], $row['text'])) {
                    continue;
                }
                if ($script !== null && $row['script'] !== $script) {
                    continue;
                }
                $out[(string) $row['id']] = [
                    'id' => (string) $row['id'],
                    'lang' => (string) $row['lang'],
                    'script' => (string) $row['script'],
                    'text' => (string) $row['text'],
                ];
            }
        }

        return $out;
    }

    public static function text(string $id): string
    {
        $all = self::titles();
        if (!isset($all[$id])) {
            throw new RuntimeException('No fixture title ' . $id);
        }

        return $all[$id]['text'];
    }

    /**
     * @return list<array{a: string, b: string, relation: string, note: string}>
     */
    public static function pairs(): array
    {
        $out = [];
        foreach (self::jsonl(self::DIR . '/pairs.jsonl') as $row) {
            if (isset($row['a'], $row['b'], $row['relation'])) {
                $out[] = [
                    'a' => (string) $row['a'],
                    'b' => (string) $row['b'],
                    'relation' => (string) $row['relation'],
                    'note' => (string) ($row['note'] ?? ''),
                ];
            }
        }

        return $out;
    }

    /**
     * The three configurations the golden file pins.
     *
     * @return array<string, Config>
     */
    public static function goldenConfigs(): array
    {
        return [
            'default' => new Config(),
            'shingles' => new Config(wordShingles: [1, 2], charNgrams: 3, digits: Config::DIGITS_KEEP),
            'loose' => new Config(
                form: Config::FORM_NFC,
                caseFold: Config::CASE_SIMPLE,
                joiners: Config::JOINERS_KEEP,
                emoji: Config::EMOJI_KEEP,
                urls: Config::URLS_KEEP,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function json(string $name): array
    {
        $raw = file_get_contents(self::DIR . '/' . $name);
        if ($raw === false) {
            throw new RuntimeException('Missing fixture ' . $name);
        }
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new RuntimeException('Bad fixture ' . $name);
        }

        return $data;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function jsonl(string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new RuntimeException('Missing fixture ' . $file);
        }
        $rows = [];
        foreach ($lines as $line) {
            $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
