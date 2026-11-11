<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Splits text into user-perceived characters, with Indic syllables whole.
 *
 * When intl is loaded, extended grapheme clusters come from ICU. Without it,
 * a built-in rule keeps every combining mark (categories Mn, Mc, Me), ZWJ,
 * ZWNJ, variation selector and the few letters Unicode treats as marks
 * attached to the character before it, attaches prepended letters to the
 * character after them, and keeps conjoining Hangul jamo together. Tokens
 * only hold letters, digits, marks and joiners, so these rules cover every
 * case the two paths can see (one known gap: a precomposed Hangul syllable
 * with a final consonant followed by a lone vowel jamo, which ICU splits).
 *
 * On top of either, one library rule is applied: a cluster that ends in a
 * joining Indic virama (ignoring trailing joiners) is merged with a following
 * cluster that starts with a letter, so a conjunct aksara is never split.
 * Newer ICU versions (Unicode 15.1, rule GB9c) already do this for some
 * scripts; the extra rule makes the result the same across ICU versions and
 * across the two paths, and extends it to every Indic script.
 *
 * @internal
 */
final class Graphemes
{
    /**
     * Viramas of the Indic scripts, plus Malayalam's vertical and circular
     * viramas.
     */
    public const VIRAMA = '\x{094D}\x{09CD}\x{0A4D}\x{0ACD}\x{0B4D}\x{0BCD}\x{0C4D}\x{0CCD}'
        . '\x{0D3B}\x{0D3C}\x{0D4D}\x{0DCA}\x{1B44}\x{A8C4}\x{11046}';

    /**
     * Viramas that join the next consonant into one aksara. Tamil pulli
     * (U+0BCD) and Sinhala al-lakuna (U+0DCA) are left out: in those scripts
     * the dead consonant is written as its own visible letter.
     */
    public const JOINING_VIRAMA = '\x{094D}\x{09CD}\x{0A4D}\x{0ACD}\x{0B4D}\x{0C4D}\x{0CCD}'
        . '\x{0D3B}\x{0D3C}\x{0D4D}\x{1B44}\x{A8C4}\x{11046}';

    /**
     * Attached to the character before: marks, joiners, variation selectors,
     * and the few letters that Unicode's grapheme rules treat as marks (Thai
     * and Lao sara am, which are spacing marks, and the halfwidth katakana
     * sound marks, which extend).
     */
    private const EXTEND = '\p{M}\x{200C}\x{200D}\x{FE00}-\x{FE0F}\x{0E33}\x{0EB3}\x{FF9E}\x{FF9F}';

    /**
     * Prepended letters (Unicode rule GB9b) that attach to the character
     * after them, such as the Malayalam dot reph.
     */
    private const PREPEND = '\x{0D4E}\x{111C2}\x{111C3}\x{11A3A}\x{11A84}-\x{11A89}\x{11D46}\x{11F02}';

    /**
     * Conjoining Hangul jamo (Unicode rules GB6 to GB8): leading consonants,
     * vowels, trailing consonants, and precomposed syllables.
     */
    private const HANGUL = '(?:[\x{1100}-\x{115F}\x{A960}-\x{A97C}]*+'
        . '(?:[\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}]++|[\x{AC00}-\x{D7A3}][\x{1160}-\x{11A7}\x{D7B0}-\x{D7C6}]*+)?+'
        . '[\x{11A8}-\x{11FF}\x{D7CB}-\x{D7FB}]*+)';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $clusters = Platform::hasIntl() ? self::icu($text) : self::fallback($text);

        return self::mergeConjuncts($clusters);
    }

    /**
     * The built-in rule only, whatever extensions are loaded. Used by the
     * tests to prove both paths agree.
     *
     * @return list<string>
     */
    public static function splitWithoutIntl(string $text): array
    {
        return $text === '' ? [] : self::mergeConjuncts(self::fallback($text));
    }

    /**
     * @return list<string>
     */
    private static function icu(string $text): array
    {
        $out = [];
        $offset = 0;
        $len = strlen($text);
        while ($offset < $len) {
            $next = $offset;
            $g = grapheme_extract($text, 1, GRAPHEME_EXTR_COUNT, $offset, $next);
            if ($g === false || $g === '' || $next <= $offset) {
                // Defensive: fall back for the rest rather than loop forever.
                foreach (self::fallback(substr($text, $offset)) as $c) {
                    $out[] = $c;
                }
                break;
            }
            $out[] = $g;
            $offset = $next;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function fallback(string $text): array
    {
        $n = preg_match_all(
            '/[' . self::EXTEND . ']++'
            . '|[' . self::PREPEND . ']*+(?:(?=[\x{1100}-\x{11FF}\x{A960}-\x{A97C}\x{AC00}-\x{D7A3}\x{D7B0}-\x{D7FB}])'
            . self::HANGUL . '|[^' . self::EXTEND . '])[' . self::EXTEND . ']*+/u',
            $text,
            $m
        );
        if ($n === false) {
            return [];
        }

        return $m[0];
    }

    /**
     * @param list<string> $clusters
     * @return list<string>
     */
    private static function mergeConjuncts(array $clusters): array
    {
        $out = [];
        $pending = null;
        foreach ($clusters as $c) {
            if (
                $pending !== null
                && preg_match('/[' . self::JOINING_VIRAMA . '][\x{200C}\x{200D}]*\z/u', $pending) === 1
                && preg_match('/\A\p{Lo}/u', $c) === 1
            ) {
                $pending .= $c;
                continue;
            }
            if ($pending !== null) {
                $out[] = $pending;
            }
            $pending = $c;
        }
        if ($pending !== null) {
            $out[] = $pending;
        }

        return $out;
    }
}
