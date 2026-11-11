<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\ConfigException;
use IndianOpinion\Simhash\Exception\InvalidInputException;
use IndianOpinion\Simhash\Exception\MissingExtensionException;

/**
 * 64-bit simhash of short text in any script.
 *
 *     $simhash = new Simhash();
 *     $a = $simhash->hash('भारत में मानसून जल्दी पहुँचा');
 *     $b = $simhash->hash('भारत में मानसून जल्दी पहुंचा');
 *     $match = $simhash->isNearDuplicate($a, $b);
 *     // $match->isNear, $match->reason, $match->distance
 *
 * Thread-safe in the PHP sense: an instance holds no per-call state that
 * leaks between calls, and it can be reused for any number of texts.
 */
final class Simhash
{
    public const KIND_WORD = 'word';
    public const KIND_SHINGLE = 'shingle';
    public const KIND_NGRAM = 'ngram';

    private static ?self $default = null;

    private readonly Config $config;
    private readonly Tokenizer $tokenizer;
    private readonly string $configId;
    private readonly bool $normalisationUnavailable;

    /** @var array<string, true> */
    private array $stopSet = [];

    /** @var array<string, float>|null */
    private ?array $weightMap = null;

    public function __construct(?Config $config = null)
    {
        if (!Platform::hasMbstring()) {
            throw new MissingExtensionException(
                'simhash-unicode needs the mbstring extension for case folding. Install or enable ext-mbstring.'
            );
        }
        $this->config = $config ?? new Config();
        $this->tokenizer = new Tokenizer($this->config);

        $needsIntl = $this->config->form !== Config::FORM_NONE || $this->config->foldLatinDiacritics;
        $this->normalisationUnavailable = $needsIntl && !Platform::hasIntl();
        $this->configId = $this->config->id() . ($this->normalisationUnavailable ? '-nonorm' : '');

        if ($this->config->stopWords !== null) {
            foreach ($this->config->stopWords->words() as $word) {
                foreach ($this->tokenTexts($word) as $t) {
                    $this->stopSet[$t] = true;
                }
            }
        }
        if ($this->config->weightMap !== null) {
            $map = [];
            foreach ($this->config->weightMap as $key => $weight) {
                foreach ($this->tokenTexts((string) $key) as $t) {
                    $map[$t] = $weight;
                }
            }
            $this->weightMap = $map;
        }
    }

    /**
     * Hash with the default configuration. Reuses one shared instance.
     */
    public static function fingerprint(string $text): Fingerprint
    {
        self::$default ??= new self();

        return self::$default->hash($text);
    }

    /**
     * Hamming distance of two 16-digit hex strings.
     */
    public static function hexDistance(string $a, string $b): int
    {
        return Bits::distance(Bits::fromHex($a), Bits::fromHex($b));
    }

    public function config(): Config
    {
        return $this->config;
    }

    /**
     * The configuration id stamped on every fingerprint from this instance.
     * It equals config()->id(), plus the suffix "-nonorm" when intl is
     * missing and the configuration asked for normalisation.
     */
    public function configId(): string
    {
        return $this->configId;
    }

    public function hash(string $text): Fingerprint
    {
        return $this->run($text, false)['fingerprint'];
    }

    /**
     * The token texts the hasher sees, in order. For debugging and tests.
     *
     * @return list<string>
     */
    public function tokens(string $text): array
    {
        return array_map(static fn (Token $t): string => $t->text, $this->run($text, true)['tokens']);
    }

    /**
     * A structured report of every step, for "why did these two match".
     *
     * @return array{
     *     input: string,
     *     normalised: string,
     *     tokens: list<array{text: string, kind: string, script: string, segment: int}>,
     *     features: list<array{text: string, kind: string, weight: float, hash: string, position: int}>,
     *     sums: array<int, float>,
     *     hex: string,
     *     empty: bool,
     *     tokenCount: int,
     *     featureCount: int,
     *     flags: list<string>,
     *     configId: string,
     *     config: string,
     *     environment: array<string, string|bool>
     * }
     */
    public function explain(string $text): array
    {
        $r = $this->run($text, true);
        $fp = $r['fingerprint'];
        $tokens = [];
        foreach ($r['tokens'] as $t) {
            $tokens[] = ['text' => $t->text, 'kind' => $t->kind, 'script' => $t->script, 'segment' => $t->segment];
        }

        return [
            'input' => $r['input'],
            'normalised' => $r['normalised'],
            'tokens' => $tokens,
            'features' => $r['features'],
            'sums' => $r['sums'],
            'hex' => $fp->hex(),
            'empty' => $fp->isEmpty(),
            'tokenCount' => (int) $fp->tokenCount(),
            'featureCount' => (int) $fp->featureCount(),
            'flags' => $fp->flags(),
            'configId' => $fp->configId(),
            'config' => $this->config->describe(),
            'environment' => self::environment(),
        ];
    }

    /**
     * Hamming distance, 0 to 64. Accepts fingerprints, 16-digit hex strings,
     * PHP ints (64-bit builds) or unsigned decimal strings, in any mix.
     * A 16-character string of hex digits is always read as hex.
     */
    public function distance(Fingerprint|string|int $a, Fingerprint|string|int $b): int
    {
        return Bits::distance(self::bytesOf($a), self::bytesOf($b));
    }

    /**
     * Near-duplicate check with the guards that matter.
     *
     * Strings are texts and are hashed first. Checks run in this order:
     * either side empty (reason empty), configuration ids differ or unknown
     * (config_mismatch), either side below minTokens (too_short), then
     * distance at most threshold (near) or not (far).
     */
    public function isNearDuplicate(Fingerprint|string $a, Fingerprint|string $b, ?int $threshold = null): MatchResult
    {
        $threshold ??= $this->config->threshold;
        if ($threshold < 0 || $threshold > 64) {
            throw new ConfigException('threshold must be from 0 to 64, got ' . $threshold . '.');
        }
        $fa = is_string($a) ? $this->hash($a) : $a;
        $fb = is_string($b) ? $this->hash($b) : $b;
        $d = $fa->distanceTo($fb);

        $reason = $this->rejectReason($fa, $fb);
        if ($reason !== null) {
            return new MatchResult(false, $reason, $d, $threshold);
        }

        return $d <= $threshold
            ? new MatchResult(true, MatchResult::NEAR, $d, $threshold)
            : new MatchResult(false, MatchResult::FAR, $d, $threshold);
    }

    /**
     * Why a pair can never be near, or null when only the distance decides.
     *
     * @internal Shared with Batch.
     */
    public function rejectReason(Fingerprint $a, Fingerprint $b): ?string
    {
        if ($a->isEmpty() || $b->isEmpty()) {
            return MatchResult::EMPTY;
        }
        if ($a->configId() === '' || $a->configId() !== $b->configId()) {
            return MatchResult::CONFIG_MISMATCH;
        }
        if (!$this->hasSignal($a) || !$this->hasSignal($b)) {
            return MatchResult::TOO_SHORT;
        }

        return null;
    }

    /**
     * True when the fingerprint is not empty and has at least minTokens
     * word tokens.
     */
    public function hasSignal(Fingerprint $fp): bool
    {
        if ($fp->isEmpty()) {
            return false;
        }
        $min = $this->config->minTokens;
        if ($min === 0) {
            return true;
        }
        $n = $fp->tokenCount();

        return $n !== null && $n >= $min;
    }

    /**
     * Versions of the libraries whose Unicode tables shape the output.
     *
     * @return array<string, string|bool>
     */
    public static function environment(): array
    {
        $env = [
            'php' => PHP_VERSION,
            'pcre' => PCRE_VERSION,
            'intl' => Platform::hasIntl(),
            'mbstring' => Platform::hasMbstring(),
            'int_size' => Platform::is64Bit() ? '64' : '32',
        ];
        if (Platform::hasIntl() && defined('INTL_ICU_VERSION')) {
            $env['icu'] = (string) constant('INTL_ICU_VERSION');
            $env['unicode'] = implode('.', array_slice(\IntlChar::getUnicodeVersion(), 0, 3));
        }

        return $env;
    }

    private static function bytesOf(Fingerprint|string|int $v): string
    {
        if ($v instanceof Fingerprint) {
            return $v->bytes();
        }
        if (is_int($v)) {
            return Bits::fromInt($v);
        }
        if (strlen($v) === 16 && strspn($v, '0123456789abcdefABCDEF') === 16) {
            return Bits::fromHex($v);
        }

        return Bits::fromUnsigned($v);
    }

    /**
     * Token texts of a stop word or weight-map key, through the same
     * pipeline as the input.
     *
     * @return list<string>
     */
    private function tokenTexts(string $word): array
    {
        TextNormalizer::assertUtf8($word);

        return array_map(static fn (Token $t): string => $t->text, $this->tokenizer->tokenize($word));
    }

    /**
     * @return array{
     *     fingerprint: Fingerprint,
     *     input: string,
     *     normalised: string,
     *     tokens: list<Token>,
     *     features: list<array{text: string, kind: string, weight: float, hash: string, position: int}>,
     *     sums: array<int, float>
     * }
     */
    private function run(string $text, bool $detail): array
    {
        $c = $this->config;
        if (preg_match('//u', $text) !== 1) {
            if (!$c->scrub) {
                TextNormalizer::assertUtf8($text);
            }
            $text = TextNormalizer::scrub($text);
        }
        $truncated = false;
        if (strlen($text) > $c->maxInputBytes) {
            if ($c->onLongInput === Config::LONG_INPUT_THROW) {
                throw new InvalidInputException(
                    'Input is ' . strlen($text) . ' bytes, over maxInputBytes ' . $c->maxInputBytes . '.'
                );
            }
            $text = TextNormalizer::truncate($text, $c->maxInputBytes);
            $truncated = true;
        }

        $tokens = $this->tokenizer->tokenize($text);
        $normalised = $this->tokenizer->lastNormalised();

        $stopSkipped = false;
        if ($this->stopSet !== [] && $tokens !== []) {
            $kept = [];
            foreach ($tokens as $t) {
                $stoppable = $t->kind === Token::KIND_WORD || $t->kind === Token::KIND_CJK;
                if ($stoppable && isset($this->stopSet[$t->text])) {
                    continue;
                }
                $kept[] = $t;
            }
            if ($kept === []) {
                $stopSkipped = true;
            } else {
                $tokens = $kept;
            }
        }

        $cjk = 0;
        $words = 0;
        foreach ($tokens as $t) {
            if ($t->kind === Token::KIND_CJK) {
                $cjk++;
            } else {
                $words++;
            }
        }
        $tokenCount = $words + intdiv($cjk, 2);

        [$features, $auto] = $this->features($tokens, $tokenCount);
        if (count($features) > $c->maxFeatures) {
            $features = array_slice($features, 0, $c->maxFeatures);
            $truncated = true;
        }

        // Bit counts are kept per distinct weight, then combined once:
        // sum = weight * (2 * ones - n) per weight group, in order of first
        // appearance. Integer counting keeps the result exact and identical
        // on 32-bit and 64-bit builds.
        $groups = [];
        $used = 0;
        $report = [];
        $hasher = $c->hasher;
        $fast = Platform::is64Bit();
        foreach ($features as [$ftext, $kind, $base, $pos, $hashInput]) {
            $w = $this->weight($ftext, $kind, $base, $pos);
            if ($w === 0.0) {
                continue;
            }
            $h = $hasher->hash($hashInput);
            if (strlen($h) !== 8) {
                throw new ConfigException(
                    'Hasher "' . $hasher->id() . '" returned ' . strlen($h) . ' bytes; exactly 8 are required.'
                );
            }
            $used++;
            // Exact key: (string) $w follows the precision ini setting and
            // could merge two different weights on one host but not another.
            $gk = pack('E', $w);
            if (!isset($groups[$gk])) {
                $groups[$gk] = new BitCounter($w, $fast);
            }
            $groups[$gk]->add($h);
            if ($detail) {
                $report[] = [
                    'text' => $ftext,
                    'kind' => $kind,
                    'weight' => $w,
                    'hash' => bin2hex($h),
                    'position' => $pos,
                ];
            }
        }

        $sums = [];
        if (!$detail && $fast && count($groups) === 1) {
            // One weight: a bit is set when more than half the features have
            // it, which is exactly "weighted sum above zero".
            $only = reset($groups);
            $bytes = Bits::fromInt($only->majority());
        } else {
            $sums = array_fill(0, 64, 0.0);
            foreach ($groups as $g) {
                $g->addTo($sums);
            }
            $bytes = self::reduce($sums);
        }

        $fp = Fingerprint::create(
            $bytes,
            $this->configId,
            $tokenCount,
            $used,
            $truncated,
            $auto,
            $stopSkipped,
            $this->normalisationUnavailable,
        );

        return [
            'fingerprint' => $fp,
            'input' => $text,
            'normalised' => $normalised,
            'tokens' => $tokens,
            'features' => $report,
            'sums' => $sums,
        ];
    }

    /**
     * Features in a fixed order: unigrams, shingles by size, explicit
     * n-grams, auto n-grams. Each is [display text, kind, base weight,
     * token position, bytes to hash].
     *
     * @param list<Token> $tokens
     * @return array{0: list<array{0: string, 1: string, 2: float, 3: int, 4: string}>, 1: bool}
     */
    private function features(array $tokens, int $tokenCount): array
    {
        $c = $this->config;
        $out = [];
        $n = count($tokens);
        foreach ($c->wordShingles as $size) {
            if ($size === 1) {
                foreach ($tokens as $i => $t) {
                    $out[] = [$t->text, self::KIND_WORD, $c->wordWeight, $i, $t->text];
                }
                continue;
            }
            for ($i = 0; $i + $size <= $n; $i++) {
                $seg = $tokens[$i]->segment;
                $parts = [];
                for ($k = 0; $k < $size; $k++) {
                    if ($tokens[$i + $k]->segment !== $seg) {
                        continue 2;
                    }
                    $parts[] = $tokens[$i + $k]->text;
                }
                $s = implode(' ', $parts);
                $out[] = [$s, self::KIND_SHINGLE, $c->shingleWeight, $i, $s];
            }
        }
        if ($c->charNgrams > 0) {
            foreach ($tokens as $i => $t) {
                if ($t->kind !== Token::KIND_WORD) {
                    continue;
                }
                foreach (self::ngrams($t->text, $c->charNgrams) as $g) {
                    $out[] = [$g, self::KIND_NGRAM, $c->ngramWeight, $i, '#' . $g];
                }
            }
        }
        $auto = false;
        $fire = $c->autoCharNgrams === Config::AUTO_NGRAMS_ON
            || ($c->autoCharNgrams === Config::AUTO_NGRAMS_AUTO && $tokenCount < Config::AUTO_NGRAM_BELOW);
        if ($fire && $c->charNgrams !== 3) {
            foreach ($tokens as $i => $t) {
                if ($t->kind !== Token::KIND_WORD) {
                    continue;
                }
                foreach (self::ngrams($t->text, 3) as $g) {
                    $out[] = [$g, self::KIND_NGRAM, $c->autoNgramWeight, $i, '#' . $g];
                    $auto = true;
                }
            }
        }

        return [$out, $auto];
    }

    /**
     * A bit is 1 when its weighted sum is strictly above zero; ties give 0.
     *
     * @param array<int, float> $sums index 0 is bit 63
     */
    private static function reduce(array $sums): string
    {
        $out = [0, 0, 0, 0];
        for ($lane = 0; $lane < 4; $lane++) {
            $v = 0;
            $base = $lane << 4;
            for ($b = 0; $b < 16; $b++) {
                if ($sums[$base + $b] > 0.0) {
                    $v |= 1 << (15 - $b);
                }
            }
            $out[$lane] = $v;
        }

        return Bits::fromLanes($out);
    }

    /**
     * Grapheme n-grams of one token with start and end markers. A token
     * shorter than n graphemes gives one n-gram: the whole token, marked.
     *
     * @return list<string>
     */
    public static function ngrams(string $token, int $n): array
    {
        $g = Graphemes::split($token);
        if (count($g) < $n) {
            return ['<' . $token . '>'];
        }
        $seq = array_merge(['<'], $g, ['>']);
        $out = [];
        $last = count($seq) - $n;
        for ($i = 0; $i <= $last; $i++) {
            $out[] = implode('', array_slice($seq, $i, $n));
        }

        return $out;
    }

    private function weight(string $text, string $kind, float $base, int $position): float
    {
        if ($this->weightMap === null && $this->config->weightCallback === null) {
            return $base;
        }
        $w = $base;
        if ($this->weightMap !== null && $kind === self::KIND_WORD && isset($this->weightMap[$text])) {
            $w = $this->weightMap[$text];
        }
        $cb = $this->config->weightCallback;
        if ($cb !== null) {
            $r = $cb($text, $kind, $position, $w);
            if (!is_int($r) && !is_float($r)) {
                throw new ConfigException('Weight callback must return a number for "' . $text . '".');
            }
            $w = (float) $r;
        }
        Config::checkWeight($w, $text);

        return $w;
    }
}
