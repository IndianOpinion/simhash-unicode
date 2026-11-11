<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\InvalidInputException;
use Normalizer as IntlNormalizer;

/**
 * Character-level steps of the pipeline: validation, truncation, invisible
 * characters, Unicode form, diacritics, harakat, case folding, apostrophes
 * and digits. Every step works on valid UTF-8 and returns valid UTF-8.
 *
 * Public so callers can inspect what the hasher sees. Its output is covered
 * by the determinism tests; method names may still change before 1.0.
 */
final class TextNormalizer
{
    /** Strict UTF-8, one code point per alternative, as bytes (no u flag). */
    private const UTF8_CHAR = '(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]'
        . '|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}'
        . '|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})';

    /** Removed without splitting the word they sit in. */
    private const INVISIBLE = '\x{00AD}\x{2060}\x{FEFF}\x{200E}\x{200F}\x{061C}\x{202A}-\x{202E}'
        . '\x{2066}-\x{2069}\x{0640}\x{180E}';

    private const HARAKAT = '\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06DC}\x{06DF}-\x{06E4}'
        . '\x{06E7}\x{06E8}\x{06EA}-\x{06ED}';

    /** Decimal digits in any script, plus the Tamil number signs for 10, 100 and 1000. */
    public const DIGIT_CLASS = '\p{Nd}\x{0BF0}-\x{0BF2}';

    /** Longest walk back, in code points, that truncate() makes to find a grapheme boundary. */
    public const TRUNCATE_MAX_WALK = 64;

    private bool $unavailable = false;

    /** @var array<string, string> */
    private static array $digitCache = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * True once a step asked for intl (NFC, NFKC or Latin diacritic folding)
     * and intl was not loaded, so the step was skipped. Reset per call to
     * resetFlags().
     */
    public function normalisationUnavailable(): bool
    {
        return $this->unavailable;
    }

    public function resetFlags(): void
    {
        $this->unavailable = false;
    }

    /**
     * Throws InvalidInputException naming the byte offset of the first
     * invalid sequence.
     */
    public static function assertUtf8(string $text): void
    {
        if (preg_match('//u', $text) === 1) {
            return;
        }
        throw new InvalidInputException(
            'Input is not valid UTF-8: first bad byte at offset ' . self::firstBadOffset($text) . '.'
        );
    }

    /**
     * Byte offset of the first invalid UTF-8 sequence at or after $from, or
     * the length of $text when there is none.
     *
     * Scans in bounded steps so the result does not depend on PCRE's JIT or
     * its backtrack limit, and stays linear in the input length.
     */
    public static function firstBadOffset(string $text, int $from = 0): int
    {
        $len = strlen($text);
        $pos = $from;
        while ($pos < $len) {
            $n = preg_match('/\G' . self::UTF8_CHAR . '{1,64}+/', $text, $m, 0, $pos);
            if ($n === false) {
                throw new InvalidInputException('Could not scan the input for invalid UTF-8: ' . preg_last_error_msg());
            }
            if ($n === 0) {
                break;
            }
            $pos += strlen($m[0]);
        }

        return $pos;
    }

    /**
     * Replaces every invalid byte sequence with U+FFFD. Linear in the input
     * length.
     */
    public static function scrub(string $text): string
    {
        $out = '';
        $len = strlen($text);
        $pos = 0;
        while ($pos < $len) {
            $good = self::firstBadOffset($text, $pos);
            $out .= substr($text, $pos, $good - $pos);
            $pos = $good;
            if ($pos < $len) {
                $out .= "\u{FFFD}";
                $pos++;
                // Skip continuation bytes of the same broken sequence.
                while ($pos < $len && (ord($text[$pos]) & 0xC0) === 0x80) {
                    $pos++;
                }
            }
        }

        return $out;
    }

    /**
     * Cuts valid UTF-8 at the last grapheme boundary at or before $maxBytes.
     * A combining mark, joiner or virama-joined consonant is never separated
     * from the character before it.
     *
     * The walk back is bounded by TRUNCATE_MAX_WALK code points. A cluster
     * longer than that (only hostile input builds one) is cut at the code
     * point boundary instead, so the cost never grows with the input.
     */
    public static function truncate(string $text, int $maxBytes): string
    {
        if (strlen($text) <= $maxBytes) {
            return $text;
        }
        $start = $maxBytes;
        while ($start > 0 && (ord($text[$start]) & 0xC0) === 0x80) {
            $start--;
        }
        $extend = '/\A[\p{M}\x{200C}\x{200D}\x{FE00}-\x{FE0F}\x{1F3FB}-\x{1F3FF}]/u';
        $join = '/\A[' . Graphemes::VIRAMA . '\x{200D}]\z/u';
        $cut = $start;
        for ($step = 0; $cut > 0; $step++) {
            if ($step >= self::TRUNCATE_MAX_WALK) {
                $cut = $start;
                break;
            }
            $prev = $cut - 1;
            while ($prev > 0 && (ord($text[$prev]) & 0xC0) === 0x80) {
                $prev--;
            }
            $last = substr($text, $prev, $cut - $prev);
            $lead = ord($text[$cut]);
            $next = substr($text, $cut, $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4)));
            if (preg_match($extend, $next) !== 1 && preg_match($join, $last) !== 1) {
                break;
            }
            $cut = $prev;
        }

        return substr($text, 0, $cut);
    }

    /**
     * Control characters become spaces; invisible format characters, bidi
     * controls and tatweel are removed; zero-width space becomes a space.
     */
    public function clean(string $text): string
    {
        $text = (string) preg_replace('/[\p{Cc}\x{200B}\x{2028}\x{2029}]/u', ' ', $text);

        return (string) preg_replace('/[' . self::INVISIBLE . ']/u', '', $text);
    }

    /**
     * Applies the configured Unicode form (NFKC, NFC or none).
     */
    public function form(string $text): string
    {
        if ($this->config->form === Config::FORM_NONE || $text === '') {
            return $text;
        }
        if (!Platform::hasIntl()) {
            $this->unavailable = true;

            return $text;
        }
        $form = $this->config->form === Config::FORM_NFKC ? IntlNormalizer::FORM_KC : IntlNormalizer::FORM_C;
        $out = IntlNormalizer::normalize($text, $form);
        if ($out === false) {
            throw new InvalidInputException('Unicode normalisation failed for the input.');
        }

        return $out;
    }

    /**
     * Removes combining marks from Latin, Greek and Cyrillic letters only.
     * Marks on every other script (Indic vowel signs, virama, nukta) are
     * never touched.
     */
    public function foldLatinDiacritics(string $text): string
    {
        if (!$this->config->foldLatinDiacritics) {
            return $text;
        }
        $hasIntl = Platform::hasIntl();
        if (!$hasIntl) {
            $this->unavailable = true;
        }

        return (string) preg_replace_callback(
            '/[\p{Latin}\p{Greek}\p{Cyrillic}]\p{M}*+/u',
            static function (array $m) use ($hasIntl): string {
                $cluster = $m[0];
                if ($hasIntl) {
                    $d = IntlNormalizer::normalize($cluster, IntlNormalizer::FORM_D);
                    $cluster = $d === false ? $cluster : $d;
                }

                return (string) preg_replace('/\p{Mn}++/u', '', $cluster);
            },
            $text
        );
    }

    public function stripHarakat(string $text): string
    {
        if (!$this->config->stripArabicHarakat) {
            return $text;
        }

        return (string) preg_replace('/[' . self::HARAKAT . ']++/u', '', $text);
    }

    public function caseFold(string $text): string
    {
        return match ($this->config->caseFold) {
            Config::CASE_FULL => mb_convert_case($text, MB_CASE_FOLD, 'UTF-8'),
            Config::CASE_SIMPLE => mb_convert_case($text, MB_CASE_FOLD_SIMPLE, 'UTF-8'),
            default => $text,
        };
    }

    /**
     * Apostrophes (U+0027, U+2019) between two letters: removed (join) or
     * turned into a separator (split).
     */
    public function apostrophes(string $text): string
    {
        $replacement = $this->config->apostrophe === Config::APOSTROPHE_JOIN ? '' : ' ';

        return (string) preg_replace("/(?<=[\\p{L}\\p{M}])['\\x{2019}](?=\\p{L})/u", $replacement, $text);
    }

    public function digits(string $text): string
    {
        switch ($this->config->digits) {
            case Config::DIGITS_KEEP:
                return $text;
            case Config::DIGITS_DROP:
                return (string) preg_replace('/[' . self::DIGIT_CLASS . ']++/u', '', $text);
            case Config::DIGITS_MASK:
                return (string) preg_replace('/[' . self::DIGIT_CLASS . ']++/u', '0', $text);
            default:
                return (string) preg_replace_callback(
                    '/\p{Nd}/u',
                    static fn (array $m): string => self::asciiDigit($m[0]),
                    $text
                );
        }
    }

    /**
     * Value of a decimal digit (category Nd) in any script, as ASCII.
     *
     * Unicode allocates every set of decimal digits as a contiguous run that
     * starts at zero, so the value is the distance from the start of the run,
     * modulo 10. No intl needed.
     */
    public static function asciiDigit(string $ch): string
    {
        if (isset(self::$digitCache[$ch])) {
            return self::$digitCache[$ch];
        }
        $cp = mb_ord($ch, 'UTF-8');
        if ($cp === false) {
            return $ch;
        }
        $back = 0;
        while ($back < 60) {
            $prev = mb_chr($cp - $back - 1, 'UTF-8');
            if ($prev === false || preg_match('/\A\p{Nd}\z/u', $prev) !== 1) {
                break;
            }
            $back++;
        }

        return self::$digitCache[$ch] = (string) ($back % 10);
    }
}
