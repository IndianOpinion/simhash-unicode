<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Splits text into user-perceived characters, with Indic syllables whole.
 *
 * When intl is loaded, extended grapheme clusters come from ICU. Without it,
 * a built-in rule keeps every combining mark (categories Mn, Mc, Me), ZWJ,
 * ZWNJ and variation selector attached to the character before it.
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

    private const EXTEND = '\p{M}\x{200C}\x{200D}\x{FE00}-\x{FE0F}';

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
        $n = preg_match_all('/[' . self::EXTEND . ']++|[^' . self::EXTEND . '][' . self::EXTEND . ']*+/u', $text, $m);
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
