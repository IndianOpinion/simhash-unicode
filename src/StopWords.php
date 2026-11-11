<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Exception\InvalidInputException;

/**
 * Caller-supplied stop-word lists, keyed by language code.
 *
 * The package ships no lists. Words are matched exactly after the same
 * normalisation and case folding as the input text; there is no stemming.
 * Lists are only ever used for array lookups, never inside a regex.
 */
final class StopWords
{
    /** @var array<string, list<string>> */
    private array $lists = [];

    /**
     * @param array<string, list<string>> $lists language code => words
     */
    public static function fromArray(array $lists): self
    {
        $s = new self();
        foreach ($lists as $lang => $words) {
            $s = $s->withList((string) $lang, $words);
        }

        return $s;
    }

    /**
     * Reads a UTF-8 text file with one word per line. Blank lines and lines
     * starting with # are ignored.
     */
    public static function fromFile(string $language, string $path): self
    {
        return (new self())->withFile($language, $path);
    }

    public function withFile(string $language, string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException('Stop-word file is missing or not readable: ' . $path);
        }
        $text = file_get_contents($path);
        if ($text === false) {
            throw new ConfigException('Could not read stop-word file: ' . $path);
        }
        if (preg_match('//u', $text) !== 1) {
            throw new InvalidInputException('Stop-word file is not valid UTF-8: ' . $path);
        }
        $words = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            $words[] = $line;
        }

        return $this->withList($language, $words);
    }

    /**
     * Returns a copy with $words added to the list for $language.
     *
     * @param list<string> $words
     */
    public function withList(string $language, array $words): self
    {
        if (preg_match('/\A[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*\z/', $language) !== 1) {
            throw new ConfigException(
                'Stop-word language code must look like "en" or "pt-BR", got "' . $language . '".'
            );
        }
        $clean = [];
        foreach ($words as $word) {
            if (!is_string($word)) {
                throw new ConfigException('Stop words must be strings.');
            }
            if (preg_match('//u', $word) !== 1) {
                throw new InvalidInputException('A stop word for "' . $language . '" is not valid UTF-8.');
            }
            $word = trim($word);
            if ($word !== '') {
                $clean[] = $word;
            }
        }
        $copy = clone $this;
        $merged = array_values(array_unique(array_merge($copy->lists[$language] ?? [], $clean)));
        sort($merged, SORT_STRING);
        $copy->lists[$language] = $merged;
        ksort($copy->lists, SORT_STRING);

        return $copy;
    }

    /**
     * @return array<string, list<string>>
     */
    public function lists(): array
    {
        return $this->lists;
    }

    /**
     * Every word from every list, as given (not yet normalised).
     *
     * @return list<string>
     */
    public function words(): array
    {
        $all = [];
        foreach ($this->lists as $words) {
            foreach ($words as $w) {
                $all[] = $w;
            }
        }

        return $all;
    }

    public function id(): string
    {
        return substr(hash('xxh64', serialize($this->lists)), 0, 12);
    }
}
