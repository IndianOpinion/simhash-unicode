<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Turns validated UTF-8 into tokens, following PRD section 4.2.
 *
 * Order: clean invisibles, Unicode form, Latin diacritics, harakat, cut out
 * URLs and e-mail addresses, cut out emoji, then for each remaining piece:
 * case fold, Unicode form again, apostrophes, digits, letter runs, script
 * boundaries, and grapheme bigrams for scripts written without spaces.
 *
 * Public so callers can inspect tokens. Output is covered by the
 * determinism tests; method names may still change before 1.0.
 */
final class Tokenizer
{
    /**
     * Script groups, in the order the detector tries them. A letter in none
     * of these (for example a Common-script letter such as U+30FC) takes the
     * script of the letter before it.
     */
    private const SCRIPTS = [
        'latin' => '\p{Latin}',
        'devanagari' => '\p{Devanagari}',
        'bengali' => '\p{Bengali}',
        'gurmukhi' => '\p{Gurmukhi}',
        'gujarati' => '\p{Gujarati}',
        'oriya' => '\p{Oriya}',
        'tamil' => '\p{Tamil}',
        'telugu' => '\p{Telugu}',
        'kannada' => '\p{Kannada}',
        'malayalam' => '\p{Malayalam}',
        'sinhala' => '\p{Sinhala}',
        'arabic' => '\p{Arabic}',
        'cjk' => '\p{Han}\p{Hiragana}\p{Katakana}\p{Bopomofo}',
        'hangul' => '\p{Hangul}',
        'thai' => '\p{Thai}',
        'lao' => '\p{Lao}',
        'khmer' => '\p{Khmer}',
        'myanmar' => '\p{Myanmar}',
        'cyrillic' => '\p{Cyrillic}',
        'greek' => '\p{Greek}',
        'hebrew' => '\p{Hebrew}',
        'armenian' => '\p{Armenian}',
        'georgian' => '\p{Georgian}',
        'ethiopic' => '\p{Ethiopic}',
        'tibetan' => '\p{Tibetan}',
        'syriac' => '\p{Syriac}',
        'thaana' => '\p{Thaana}',
    ];

    /** Malayalam consonant + virama + ZWJ => atomic chillu (Unicode 5.1). */
    private const CHILLU = [
        "\u{0D23}\u{0D4D}\u{200D}" => "\u{0D7A}",
        "\u{0D28}\u{0D4D}\u{200D}" => "\u{0D7B}",
        "\u{0D30}\u{0D4D}\u{200D}" => "\u{0D7C}",
        "\u{0D32}\u{0D4D}\u{200D}" => "\u{0D7D}",
        "\u{0D33}\u{0D4D}\u{200D}" => "\u{0D7E}",
        "\u{0D15}\u{0D4D}\u{200D}" => "\u{0D7F}",
    ];

    /** Scripts written without spaces between words (B14). */
    public const NO_SPACE = ['cjk', 'thai', 'lao', 'khmer', 'myanmar'];

    private const URL_OR_EMAIL = '/(?<![\p{L}\p{N}\p{M}.@\/])(?:(?:https?:\/\/|www\.)[^\s]++'
        . '|(?<![_%+\-])[\p{L}\p{N}\p{M}._%+\-]++@[\p{L}\p{N}\p{M}\-]++(?:\.[\p{L}\p{N}\p{M}\-]++)+)/iu';

    private const EMOJI_PIC = '[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2300}-\x{23FF}\x{2B00}-\x{2BFF}'
        . '\x{3030}\x{303D}\x{3297}\x{3299}\x{00A9}\x{00AE}\x{203C}\x{2049}\x{2139}]';

    private const SEGMENT_BREAK = '/[!?\x{0964}\x{0965}\x{3002}\x{FF01}\x{FF1F}\x{061F}\x{06D4}\x{2026}]|\.(?=\s|\z)/u';

    /** @var array<string, string> */
    private static array $scriptCache = [];

    private readonly TextNormalizer $normalizer;
    private readonly string $emojiPattern;
    private readonly string $scriptPattern;

    private string $normalised = '';

    public function __construct(private readonly Config $config)
    {
        $this->normalizer = new TextNormalizer($config);
        $elem = self::EMOJI_PIC . '[\x{FE0E}\x{FE0F}]?[\x{1F3FB}-\x{1F3FF}]?';
        $this->emojiPattern = '/[\x{1F1E6}-\x{1F1FF}]{2}'
            . '|\x{1F3F4}[\x{E0020}-\x{E007E}]++\x{E007F}'
            . '|[0-9#*]\x{FE0F}?\x{20E3}'
            . '|' . $elem . '(?:\x{200D}' . $elem . ')*+/u';
        $this->scriptPattern = '/\A(?:([' . implode('])|([', self::SCRIPTS) . ']))\z/u';
    }

    public function normalizer(): TextNormalizer
    {
        return $this->normalizer;
    }

    /**
     * The text after every character-level step, as tokens see it. Valid
     * after tokenize().
     */
    public function lastNormalised(): string
    {
        return $this->normalised;
    }

    /**
     * @return list<Token>
     */
    public function tokenize(string $text): array
    {
        $this->normalised = '';
        $n = $this->normalizer;
        $text = $n->clean($text);
        $text = $n->form($text);
        $text = $n->foldLatinDiacritics($text);
        $text = $n->stripHarakat($text);

        $tokens = [];
        $segment = 0;
        $parts = [];
        foreach ($this->splitUrls($text) as [$type, $value]) {
            if ($type === 'url') {
                if ($this->config->urls === Config::URLS_DROP || $value === '') {
                    $parts[] = ' ';
                    $segment++;
                    continue;
                }
                $host = $n->caseFold($value);
                $tokens[] = new Token($host, Token::KIND_URL, 'url', $segment);
                $parts[] = ' ' . $host . ' ';
                continue;
            }
            foreach ($this->splitEmoji($value) as [$etype, $evalue]) {
                if ($etype === 'emoji') {
                    if ($this->config->emoji === Config::EMOJI_KEEP) {
                        $tokens[] = new Token($evalue, Token::KIND_EMOJI, 'emoji', $segment);
                        $parts[] = ' ' . $evalue . ' ';
                    } else {
                        $parts[] = ' ';
                    }
                    continue;
                }
                $piece = $n->caseFold($evalue);
                $piece = $n->form($piece);
                $piece = $n->apostrophes($piece);
                $piece = $n->digits($piece);
                $parts[] = $piece;
                $chunks = preg_split(self::SEGMENT_BREAK, $piece);
                $first = true;
                foreach ($chunks === false ? [$piece] : $chunks as $chunk) {
                    if (!$first) {
                        $segment++;
                    }
                    $first = false;
                    foreach ($this->words($chunk, $segment) as $t) {
                        $tokens[] = $t;
                    }
                }
            }
        }
        $this->normalised = trim((string) preg_replace('/\s++/u', ' ', implode('', $parts)));

        return $tokens;
    }

    /**
     * Script group of one letter, or '' when the letter has no script of its
     * own (Common or Inherited).
     */
    public function scriptOf(string $letter): string
    {
        if (isset(self::$scriptCache[$letter])) {
            return self::$scriptCache[$letter];
        }
        $script = '';
        if (preg_match($this->scriptPattern, $letter, $m) === 1) {
            $names = array_keys(self::SCRIPTS);
            $script = $names[count($m) - 2] ?? '';
        } elseif (
            preg_match('/\A\p{L}\z/u', $letter) === 1
            && preg_match('/\A[\p{Common}\p{Inherited}]\z/u', $letter) !== 1
        ) {
            $script = 'other';
        }

        return self::$scriptCache[$letter] = $script;
    }

    /**
     * @return list<array{0: string, 1: string}> pairs of [type, value]
     */
    private function splitUrls(string $text): array
    {
        if ($this->config->urls === Config::URLS_KEEP) {
            return [['text', $text]];
        }
        if (preg_match_all(self::URL_OR_EMAIL, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [['text', $text]];
        }
        $out = [];
        $pos = 0;
        foreach ($m[0] as [$match, $offset]) {
            $trimmed = (string) preg_replace('/[.,;:!?)\]}\'"\x{2019}\x{201D}>]++\z/u', '', $match);
            $out[] = ['text', substr($text, $pos, $offset - $pos)];
            $out[] = ['url', self::host($trimmed)];
            $pos = $offset + strlen($trimmed);
        }
        $out[] = ['text', substr($text, $pos)];

        return $out;
    }

    /**
     * Host of a URL without www., or the domain of an e-mail address.
     */
    public static function host(string $urlOrEmail): string
    {
        $s = $urlOrEmail;
        if (preg_match('/\A[^@\/:]++@(.++)\z/u', $s, $m) === 1 && stripos($s, 'www.') !== 0) {
            $s = $m[1];
        } else {
            $s = (string) preg_replace('/\A[a-z]++:\/\//i', '', $s);
            $s = (string) preg_replace('/[\/?#].*+\z/us', '', $s);
            $s = (string) preg_replace('/\A[^@]*+@/u', '', $s);
            $s = (string) preg_replace('/:[0-9]*+\z/', '', $s);
        }
        $s = (string) preg_replace('/\Awww\./i', '', $s);

        return trim($s, '.');
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function splitEmoji(string $text): array
    {
        if (preg_match_all($this->emojiPattern, $text, $m, PREG_OFFSET_CAPTURE) === 0) {
            return [['text', $text]];
        }
        $out = [];
        $pos = 0;
        foreach ($m[0] as [$match, $offset]) {
            $out[] = ['text', substr($text, $pos, $offset - $pos)];
            $out[] = ['emoji', $match];
            $pos = $offset + strlen($match);
        }
        $out[] = ['text', substr($text, $pos)];

        return $out;
    }

    /**
     * Letter runs of one chunk, split at script boundaries.
     *
     * @return list<Token>
     */
    private function words(string $chunk, int $segment): array
    {
        if (preg_match_all('/[\p{L}\p{N}][\p{L}\p{N}\p{M}\x{200C}\x{200D}]*+/u', $chunk, $m) === 0) {
            return [];
        }
        $tokens = [];
        $whole = $this->singleScript($chunk);
        foreach ($m[0] as $run) {
            if (preg_match('/\A[A-Za-z0-9]++\z/', $run) === 1) {
                $tokens[] = new Token($run, Token::KIND_WORD, 'latin', $segment);
                continue;
            }
            $pieces = $whole === null ? $this->byScript($run) : [[$whole, $run]];
            foreach ($pieces as [$script, $piece]) {
                $piece = self::trimJoiners($piece);
                if ($piece === '') {
                    continue;
                }
                if (in_array($script, self::NO_SPACE, true)) {
                    foreach ($this->noSpaceTokens($piece, $script, $segment) as $t) {
                        $tokens[] = $t;
                    }
                    continue;
                }
                $text = $this->stripJoiners($piece);
                if ($text !== '') {
                    $tokens[] = new Token($text, Token::KIND_WORD, $script === '' ? 'common' : $script, $segment);
                }
            }
        }

        return $tokens;
    }

    /**
     * The script of every letter in $text when they all share one (letters
     * with no script of their own do not count), '' when there are no
     * letters, or null when scripts are mixed or unlisted.
     */
    private function singleScript(string $text): ?string
    {
        if (preg_match('/\p{L}/u', $text, $first) !== 1) {
            return '';
        }
        $script = $this->scriptOf($first[0]);
        if ($script === '' || $script === 'other') {
            return null;
        }
        $same = '/\A(?:[' . self::SCRIPTS[$script] . '\p{Common}\p{Inherited}]|\P{L})*+\z/u';

        return preg_match($same, $text) === 1 ? $script : null;
    }

    /**
     * @return list<array{0: string, 1: string}> pairs of [script, piece]
     */
    private function byScript(string $run): array
    {
        $single = $this->singleScript($run);
        if ($single !== null) {
            return [[$single, $run]];
        }

        $chars = preg_split('//u', $run, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return [];
        }
        $pieces = [];
        $current = '';
        $script = '';
        foreach ($chars as $ch) {
            if (preg_match('/\A\p{L}\z/u', $ch) === 1) {
                $s = $this->scriptOf($ch);
                if ($s !== '' && $script !== '' && $s !== $script) {
                    $pieces[] = [$script, $current];
                    $current = '';
                }
                if ($s !== '') {
                    $script = $s;
                }
            } elseif ($current === '' && preg_match('/\A[\p{M}\x{200C}\x{200D}]\z/u', $ch) === 1) {
                continue;
            }
            $current .= $ch;
        }
        if ($current !== '') {
            $pieces[] = [$script, $current];
        }

        return $pieces;
    }

    /**
     * Joiners only count between two token characters. A trailing joiner is
     * kept only after a mark (the older Malayalam chillu: consonant, virama,
     * ZWJ).
     */
    private static function trimJoiners(string $piece): string
    {
        if (!str_contains($piece, "\u{200C}") && !str_contains($piece, "\u{200D}")) {
            return $piece;
        }
        $piece = (string) preg_replace('/\A[\x{200C}\x{200D}]++/u', '', $piece);

        return (string) preg_replace('/(?<!\p{M})[\x{200C}\x{200D}]++\z/u', '', $piece);
    }

    private function stripJoiners(string $piece): string
    {
        if (
            $this->config->joiners === Config::JOINERS_KEEP
            || (!str_contains($piece, "\u{200C}") && !str_contains($piece, "\u{200D}"))
        ) {
            return $piece;
        }
        // Older Malayalam chillu encoding (consonant, virama, ZWJ) to the
        // atomic chillu letter, so both encodings give the same token.
        $piece = strtr($piece, self::CHILLU);

        return (string) preg_replace('/[\x{200C}\x{200D}]++/u', '', $piece);
    }

    /**
     * Grapheme bigrams for a run in a script written without spaces. Digit
     * runs inside it become ordinary word tokens.
     *
     * @return list<Token>
     */
    private function noSpaceTokens(string $piece, string $script, int $segment): array
    {
        $tokens = [];
        $parts = preg_split('/(\p{N}++)/u', $piece, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        foreach ($parts === false ? [$piece] : $parts as $part) {
            if (preg_match('/\A\p{N}/u', $part) === 1) {
                $tokens[] = new Token($part, Token::KIND_WORD, $script, $segment);
                continue;
            }
            $g = Graphemes::split($this->stripJoiners($part));
            $count = count($g);
            if ($count === 1) {
                $tokens[] = new Token($g[0], Token::KIND_CJK, $script, $segment);
                continue;
            }
            for ($i = 0; $i + 1 < $count; $i++) {
                $tokens[] = new Token($g[$i] . $g[$i + 1], Token::KIND_CJK, $script, $segment);
            }
        }

        return $tokens;
    }
}
