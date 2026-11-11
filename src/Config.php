<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use Closure;
use IndianOpinion\Simhash\Exception\ConfigException;

/**
 * Every setting of the hasher. Immutable; validated on construction.
 *
 * Build it with named arguments, or start from the defaults and use the
 * with*() methods:
 *
 *     $config = new Config(digits: Config::DIGITS_MASK);
 *     $config = (new Config())->withWordShingles([1, 2]);
 *
 * Settings that change the bits of a fingerprint feed the configuration id
 * (see id()). Settings that only change decisions (minTokens, threshold,
 * scrub, onLongInput, batchIndexCutover) do not.
 */
final class Config
{
    /**
     * Bumped whenever the same text and settings would give different bits.
     */
    public const ALGORITHM_VERSION = 1;

    public const FORM_NFKC = 'NFKC';
    public const FORM_NFC = 'NFC';
    public const FORM_NONE = 'none';

    public const CASE_FULL = 'full';
    public const CASE_SIMPLE = 'simple';
    public const CASE_OFF = 'off';

    public const JOINERS_STRIP = 'strip';
    public const JOINERS_KEEP = 'keep';

    public const APOSTROPHE_JOIN = 'join';
    public const APOSTROPHE_SPLIT = 'split';

    public const DIGITS_FOLD = 'fold';
    public const DIGITS_KEEP = 'keep';
    public const DIGITS_DROP = 'drop';
    public const DIGITS_MASK = 'mask';

    public const URLS_HOST = 'host';
    public const URLS_DROP = 'drop';
    public const URLS_KEEP = 'keep';

    public const EMOJI_DROP = 'drop';
    public const EMOJI_KEEP = 'keep';

    public const AUTO_NGRAMS_AUTO = 'auto';
    public const AUTO_NGRAMS_ON = 'on';
    public const AUTO_NGRAMS_OFF = 'off';

    public const LONG_INPUT_TRUNCATE = 'truncate';
    public const LONG_INPUT_THROW = 'throw';

    /**
     * Auto character trigrams fire when a text has fewer word tokens than this.
     */
    public const AUTO_NGRAM_BELOW = 3;

    public readonly Hasher $hasher;

    /** @var list<int> */
    public readonly array $wordShingles;

    /** @var array<string, float>|null */
    public readonly ?array $weightMap;

    public readonly ?Closure $weightCallback;

    private readonly string $id;

    /**
     * @param list<int> $wordShingles
     * @param array<string, float|int>|callable|null $weights map of token to weight, or
     *        fn(string $feature, string $kind, int $position, float $default): float (needs $weightsId)
     */
    public function __construct(
        ?Hasher $hasher = null,
        public readonly string $form = self::FORM_NFKC,
        public readonly string $caseFold = self::CASE_FULL,
        public readonly bool $foldLatinDiacritics = false,
        public readonly bool $stripArabicHarakat = false,
        public readonly string $joiners = self::JOINERS_STRIP,
        public readonly string $apostrophe = self::APOSTROPHE_JOIN,
        public readonly string $digits = self::DIGITS_FOLD,
        public readonly string $urls = self::URLS_HOST,
        public readonly string $emoji = self::EMOJI_DROP,
        public readonly ?StopWords $stopWords = null,
        array $wordShingles = [1],
        public readonly int $charNgrams = 0,
        public readonly string $autoCharNgrams = self::AUTO_NGRAMS_AUTO,
        public readonly float $wordWeight = 1.0,
        public readonly float $shingleWeight = 1.0,
        public readonly float $ngramWeight = 0.25,
        public readonly float $autoNgramWeight = 1.0,
        array|callable|null $weights = null,
        public readonly string $weightsId = '',
        public readonly int $minTokens = 3,
        public readonly int $threshold = 6,
        public readonly int $maxInputBytes = 4096,
        public readonly string $onLongInput = self::LONG_INPUT_TRUNCATE,
        public readonly int $maxFeatures = 2048,
        public readonly bool $scrub = false,
        public readonly int $batchIndexCutover = 2000,
    ) {
        $this->hasher = $hasher ?? new Xxh64Hasher();

        self::oneOf('form', $form, [self::FORM_NFKC, self::FORM_NFC, self::FORM_NONE]);
        self::oneOf('caseFold', $caseFold, [self::CASE_FULL, self::CASE_SIMPLE, self::CASE_OFF]);
        self::oneOf('joiners', $joiners, [self::JOINERS_STRIP, self::JOINERS_KEEP]);
        self::oneOf('apostrophe', $apostrophe, [self::APOSTROPHE_JOIN, self::APOSTROPHE_SPLIT]);
        self::oneOf('digits', $digits, [self::DIGITS_FOLD, self::DIGITS_KEEP, self::DIGITS_DROP, self::DIGITS_MASK]);
        self::oneOf('urls', $urls, [self::URLS_HOST, self::URLS_DROP, self::URLS_KEEP]);
        self::oneOf('emoji', $emoji, [self::EMOJI_DROP, self::EMOJI_KEEP]);
        self::oneOf('autoCharNgrams', $autoCharNgrams, [
            self::AUTO_NGRAMS_AUTO, self::AUTO_NGRAMS_ON, self::AUTO_NGRAMS_OFF,
        ]);
        self::oneOf('onLongInput', $onLongInput, [self::LONG_INPUT_TRUNCATE, self::LONG_INPUT_THROW]);

        if ($wordShingles === [] || !array_is_list($wordShingles)) {
            throw new ConfigException('wordShingles must be a non-empty list of sizes from 1 to 4.');
        }
        foreach ($wordShingles as $size) {
            if (!is_int($size) || $size < 1 || $size > 4) {
                throw new ConfigException('wordShingles sizes must be integers from 1 to 4.');
            }
        }
        $sizes = array_values(array_unique($wordShingles));
        sort($sizes);
        $this->wordShingles = $sizes;

        if ($charNgrams !== 0 && ($charNgrams < 2 || $charNgrams > 5)) {
            throw new ConfigException('charNgrams must be 0 (off) or a size from 2 to 5, got ' . $charNgrams . '.');
        }
        self::range('minTokens', $minTokens, 0, 32);
        self::range('threshold', $threshold, 0, 64);
        self::range('maxInputBytes', $maxInputBytes, 64, 1048576);
        self::range('maxFeatures', $maxFeatures, 16, 65536);
        self::range('batchIndexCutover', $batchIndexCutover, 0, PHP_INT_MAX);

        foreach (
            [
                'wordWeight' => $wordWeight,
                'shingleWeight' => $shingleWeight,
                'ngramWeight' => $ngramWeight,
                'autoNgramWeight' => $autoNgramWeight,
            ] as $name => $w
        ) {
            if (!is_finite($w) || $w < 0.0) {
                throw new ConfigException($name . ' must be a finite number of zero or more.');
            }
        }

        if (is_array($weights)) {
            $map = [];
            foreach ($weights as $token => $w) {
                $token = (string) $token;
                if (!is_int($w) && !is_float($w)) {
                    throw new ConfigException('Weight for token "' . $token . '" must be a number.');
                }
                self::checkWeight((float) $w, $token);
                $map[$token] = (float) $w;
            }
            ksort($map, SORT_STRING);
            $this->weightMap = $map;
            $this->weightCallback = null;
        } elseif ($weights !== null) {
            if (trim($weightsId) === '') {
                throw new ConfigException(
                    'A weight callback needs a weightsId that names it. A closure cannot be identified by value, '
                    . 'and without a name two different callbacks would share one configuration id.'
                );
            }
            $this->weightMap = null;
            $this->weightCallback = Closure::fromCallable($weights);
        } else {
            $this->weightMap = null;
            $this->weightCallback = null;
        }

        $this->id = $this->computeId();
    }

    /**
     * Throws ConfigException unless $weight is finite and not negative.
     */
    public static function checkWeight(float $weight, string $token): void
    {
        if (is_nan($weight) || is_infinite($weight) || $weight < 0.0) {
            throw new ConfigException(
                'Weight for "' . $token . '" must be a finite number of zero or more, got '
                . var_export($weight, true) . '.'
            );
        }
    }

    /**
     * Short stable id of every setting that changes the bits of a fingerprint.
     *
     * Store it next to each fingerprint. When it changes, re-hash from the
     * source text: fingerprints made under different ids are not comparable.
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * The canonical description the id is derived from. Useful in bug reports.
     */
    public function describe(): string
    {
        if ($this->weightMap !== null) {
            $pairs = [];
            foreach ($this->weightMap as $token => $w) {
                $pairs[] = strlen((string) $token) . ':' . $token . '=' . self::num($w);
            }
            $weights = 'map:' . hash('xxh64', implode(';', $pairs));
        } elseif ($this->weightCallback !== null) {
            $weights = 'callback:' . $this->weightsId;
        } else {
            $weights = 'none';
        }

        return implode(';', [
            'v=' . self::ALGORITHM_VERSION,
            'hasher=' . $this->hasher->id(),
            'form=' . $this->form,
            'case=' . $this->caseFold,
            'latin=' . ($this->foldLatinDiacritics ? '1' : '0'),
            'harakat=' . ($this->stripArabicHarakat ? '1' : '0'),
            'joiners=' . $this->joiners,
            'apos=' . $this->apostrophe,
            'digits=' . $this->digits,
            'urls=' . $this->urls,
            'emoji=' . $this->emoji,
            'stop=' . ($this->stopWords === null ? 'none' : $this->stopWords->id()),
            'shingles=' . implode(',', $this->wordShingles),
            'ngrams=' . $this->charNgrams,
            'auto=' . $this->autoCharNgrams,
            'w=' . implode(',', [
                self::num($this->wordWeight),
                self::num($this->shingleWeight),
                self::num($this->ngramWeight),
                self::num($this->autoNgramWeight),
            ]),
            'weights=' . $weights,
            'maxbytes=' . $this->maxInputBytes,
            'maxfeat=' . $this->maxFeatures,
        ]);
    }

    public function withHasher(Hasher $hasher): self
    {
        return $this->with('hasher', $hasher);
    }

    public function withForm(string $form): self
    {
        return $this->with('form', $form);
    }

    public function withCaseFold(string $caseFold): self
    {
        return $this->with('caseFold', $caseFold);
    }

    public function withFoldLatinDiacritics(bool $on): self
    {
        return $this->with('foldLatinDiacritics', $on);
    }

    public function withStripArabicHarakat(bool $on): self
    {
        return $this->with('stripArabicHarakat', $on);
    }

    public function withJoiners(string $joiners): self
    {
        return $this->with('joiners', $joiners);
    }

    public function withApostrophe(string $apostrophe): self
    {
        return $this->with('apostrophe', $apostrophe);
    }

    public function withDigits(string $digits): self
    {
        return $this->with('digits', $digits);
    }

    public function withUrls(string $urls): self
    {
        return $this->with('urls', $urls);
    }

    public function withEmoji(string $emoji): self
    {
        return $this->with('emoji', $emoji);
    }

    public function withStopWords(?StopWords $stopWords): self
    {
        return $this->with('stopWords', $stopWords);
    }

    /**
     * @param list<int> $sizes
     */
    public function withWordShingles(array $sizes): self
    {
        return $this->with('wordShingles', $sizes);
    }

    public function withCharNgrams(int $size): self
    {
        return $this->with('charNgrams', $size);
    }

    public function withAutoCharNgrams(string $mode): self
    {
        return $this->with('autoCharNgrams', $mode);
    }

    public function withKindWeights(float $word, float $shingle, float $ngram, float $autoNgram): self
    {
        $args = $this->args();
        $args['wordWeight'] = $word;
        $args['shingleWeight'] = $shingle;
        $args['ngramWeight'] = $ngram;
        $args['autoNgramWeight'] = $autoNgram;

        return new self(...$args);
    }

    /**
     * @param array<string, float|int>|callable|null $weights
     */
    public function withWeights(array|callable|null $weights, string $weightsId = ''): self
    {
        $args = $this->args();
        $args['weights'] = $weights;
        $args['weightsId'] = $weightsId;

        return new self(...$args);
    }

    public function withMinTokens(int $minTokens): self
    {
        return $this->with('minTokens', $minTokens);
    }

    public function withThreshold(int $threshold): self
    {
        return $this->with('threshold', $threshold);
    }

    public function withMaxInputBytes(int $bytes): self
    {
        return $this->with('maxInputBytes', $bytes);
    }

    public function withOnLongInput(string $mode): self
    {
        return $this->with('onLongInput', $mode);
    }

    public function withMaxFeatures(int $max): self
    {
        return $this->with('maxFeatures', $max);
    }

    public function withScrub(bool $on): self
    {
        return $this->with('scrub', $on);
    }

    public function withBatchIndexCutover(int $items): self
    {
        return $this->with('batchIndexCutover', $items);
    }

    private function with(string $name, mixed $value): self
    {
        $args = $this->args();
        $args[$name] = $value;

        return new self(...$args);
    }

    /**
     * @return array<string, mixed>
     */
    private function args(): array
    {
        return [
            'hasher' => $this->hasher,
            'form' => $this->form,
            'caseFold' => $this->caseFold,
            'foldLatinDiacritics' => $this->foldLatinDiacritics,
            'stripArabicHarakat' => $this->stripArabicHarakat,
            'joiners' => $this->joiners,
            'apostrophe' => $this->apostrophe,
            'digits' => $this->digits,
            'urls' => $this->urls,
            'emoji' => $this->emoji,
            'stopWords' => $this->stopWords,
            'wordShingles' => $this->wordShingles,
            'charNgrams' => $this->charNgrams,
            'autoCharNgrams' => $this->autoCharNgrams,
            'wordWeight' => $this->wordWeight,
            'shingleWeight' => $this->shingleWeight,
            'ngramWeight' => $this->ngramWeight,
            'autoNgramWeight' => $this->autoNgramWeight,
            'weights' => $this->weightMap ?? $this->weightCallback,
            'weightsId' => $this->weightsId,
            'minTokens' => $this->minTokens,
            'threshold' => $this->threshold,
            'maxInputBytes' => $this->maxInputBytes,
            'onLongInput' => $this->onLongInput,
            'maxFeatures' => $this->maxFeatures,
            'scrub' => $this->scrub,
            'batchIndexCutover' => $this->batchIndexCutover,
        ];
    }

    private function computeId(): string
    {
        return 'shu' . self::ALGORITHM_VERSION . '-' . substr(hash('xxh64', $this->describe()), 0, 12);
    }

    /**
     * @param list<string> $allowed
     */
    private static function oneOf(string $name, string $value, array $allowed): void
    {
        if (!in_array($value, $allowed, true)) {
            throw new ConfigException(
                $name . ' must be one of ' . implode(', ', $allowed) . ', got "' . $value . '".'
            );
        }
    }

    private static function range(string $name, int $value, int $min, int $max): void
    {
        if ($value < $min || $value > $max) {
            throw new ConfigException($name . ' must be from ' . $min . ' to ' . $max . ', got ' . $value . '.');
        }
    }

    /**
     * Canonical text of a weight, independent of the precision ini settings.
     * Six decimals when that is exact, else the IEEE 754 bytes in hex, so two
     * weights that give different bits never share an id.
     */
    private static function num(float $f): string
    {
        $short = rtrim(rtrim(sprintf('%.6F', $f), '0'), '.');

        return (float) $short === $f ? $short : 'x' . bin2hex(pack('E', $f));
    }
}
