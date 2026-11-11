# PRD: simhash-unicode

Status: draft for review. Package `indianopinion/simhash-unicode`, namespace
`IndianOpinion\Simhash`, PHP 8.1 or later, no runtime dependencies.

This document describes behaviour, interfaces, cases and tests. It contains
no code. Names of classes and methods below are proposals for the builder,
who may refine them in `docs/ARCHITECTURE.md` as long as every case in
section 4 keeps its test in section 12.

## 1. Problem

Simhash turns a short text into a 64-bit fingerprint so that similar texts
get fingerprints that differ in few bits. News systems use it to spot the
same story arriving from several sources. Most PHP simhash code splits text
on anything that is not `a-z` or `0-9`. That works for English and fails
silently for everything else.

Measured result: in production on indianopinion.org, an ASCII-only tokeniser
gave every Devanagari, Telugu and Tamil title zero tokens, so all of them
hashed to `0000000000000000`, sat at distance 0 from each other, and one
story group absorbed six unrelated stories from three outlets.

Nothing raised an error. The hash looked valid. The distance looked like a
perfect match. The bug only showed up as wrong editorial output.

This library fixes the failure at its root: tokens are letters, digits and
combining marks in any script, an empty token set is reported as empty
rather than as a real fingerprint, and a near-duplicate check refuses to
match on too little signal.

## 2. Goals and non-goals

### Goals

1. A 64-bit simhash of a short text (a title, a headline, a subject line)
   in any script Unicode encodes.
2. Unicode-aware tokenisation: letters and digits in any script, with
   combining marks kept inside their syllable, so an Indic syllable is never
   split.
3. Optional word shingles and character n-grams, for very short text, for
   agglutinative languages, and for scripts written without spaces.
4. Configurable normalisation: Unicode form (none, NFC, NFKC), case folding,
   punctuation, digits, URLs, emoji, and optional per-language stop-word
   lists supplied by the caller.
5. Token weighting: by count, by a caller-supplied weight map, or by a
   caller-supplied callback.
6. A documented token hash function, with the reason it was chosen.
7. Hex, signed integer and unsigned decimal string representations.
8. Hamming distance between any two fingerprints.
9. A near-duplicate check with a threshold and a minimum-signal guard.
10. Batch helpers that find all near pairs in a list, and group them.
11. Tests that reproduce the exact production failure and prove it fixed.
12. An honest statement of what simhash cannot do.

### Non-goals

- Full-text or document similarity. Use shingling plus MinHash or a search
  engine for long text.
- Embeddings or any semantic similarity. Two titles that say the same thing
  in different words, or in different languages, are far apart here.
- Locale-specific stemming or lemmatisation.
- Transliteration between scripts. A Hindi title and its romanised form are
  different inputs.
- Translation, language detection as a product feature, or spelling
  correction.
- Persistent storage, indexing servers, or a database schema.

## 3. Users and setting

- PHP developers deduplicating titles, headlines, product names, ticket
  subjects or feed items, in any language.
- News aggregators and feed readers that ingest several sources and must
  group the same story, especially across Indian languages.
- Runs inside a web request, a cron job or a queue worker. Pure library: no
  network, no files written, no global state beyond optional static caches
  of immutable tables.
- Installed with Composer, or without Composer by one `require` of a
  bootstrap file that registers an autoloader for the package only.

## 4. Behaviour

Terms used below. A *grapheme* is what a reader sees as one character. An
*aksara* is an Indic orthographic syllable: one or more consonants joined by
virama, with their vowel sign and other marks. A *token* is the unit fed to
the hash. A *feature* is a token, a word shingle or a character n-gram,
with a weight. *Empty* means zero features after normalisation.

### 4.1 Pipeline (applies to every input)

1. The input must be a PHP string of valid UTF-8. Invalid UTF-8 raises
   `InvalidInputException` naming the byte offset of the first bad
   sequence. It is never hashed and never treated as empty (fail closed:
   a regex over invalid UTF-8 returns failure, which naive code reads as
   "no tokens", which is exactly the zero-hash bug). An opt-in `scrub`
   setting replaces bad sequences with U+FFFD first and then proceeds.
2. A NUL byte or other C0/C1 control character is treated as a separator,
   never as part of a token.
3. Input longer than `maxInputBytes` (default 4096) is handled per
   `onLongInput`: `truncate` (default) cuts at the last grapheme boundary
   at or before the limit; `throw` raises `InvalidInputException`.
   Truncation is reported on the fingerprint (`truncated` flag).
4. Unicode normalisation is applied (section 4.3).
5. URLs, e-mail addresses, emoji and digits are handled per settings
   (sections 4.5 to 4.7).
6. Case folding is applied (section 4.3).
7. The text is segmented into tokens (section 4.2).
8. Stop words are removed if a list is configured (section 4.4).
9. Features are built: unigrams, plus word shingles and character n-grams
   when configured or when the auto rule fires (section 4.8).
10. Each feature is hashed to 64 bits and weighted (sections 4.9, 4.10).
11. The 64 weighted bit sums are reduced to the fingerprint (section 4.11).

### 4.2 Tokenisation per script

A token is a maximal run of characters with general category Letter (L*),
Number (N*) or Mark (M*), plus the joiners ZWJ (U+200D) and ZWNJ (U+200C)
when they sit between two such characters. Everything else separates
tokens. A mark never starts a token on its own: a mark that follows a
separator (a stray combining mark) attaches to nothing and is dropped.

Cases:

- **B1 Latin.** "Monsoon arrives early in Kerala" gives five tokens after
  case folding: monsoon, arrives, early, in, kerala.
- **B2 Latin with diacritics.** "Café São Paulo" keeps é and ã as
  letters. With `foldLatinDiacritics` on (default off), marks on Latin,
  Greek and Cyrillic base letters are removed after NFD, giving cafe, sao,
  paulo. Folding never touches marks on any other script (see B4 and
  lesson L4).
- **B3 Apostrophes and hyphens.** U+0027 and U+2019 between two letters
  are removed and the letters joined ("India's" gives indias). Hyphens and
  dashes of every kind separate tokens ("Indo-Pacific" gives indo,
  pacific). Configurable: `apostrophe` = `join` (default) or `split`.
- **B4 Devanagari.** Vowel signs (matras), virama, nukta, anusvara,
  candrabindu and visarga are marks and stay inside the token. "भारत में
  मानसून" gives three tokens: भारत, में, मानसून. The token count is
  never zero for a title that has Devanagari letters.
- **B5 Devanagari nukta forms.** U+0958 to U+095F (precomposed nukta
  letters) and the base letter followed by U+093C are the same text after
  NFC or NFKC (both decompose them, as these characters are composition
  exclusions). They hash the same when normalisation is on; with
  normalisation `none` they may differ, and the docs say so.
- **B6 Bengali and Assamese.** Same rules as B4. Khanda ta (U+09CE) is a
  letter. "বাংলাদেশে বন্যা" gives two tokens.
- **B7 Telugu.** Vowel signs, virama and length marks stay inside the
  token. A Telugu title of three words gives three tokens, never zero.
- **B8 Tamil.** Pulli (U+0BCD) and vowel signs are marks and stay inside
  the token. Tamil digits (U+0BE6 to U+0BEF) are digits; Tamil number
  signs for ten, hundred and thousand (U+0BF0 to U+0BF2) are numbers and
  are treated as digits for the digit setting.
- **B9 Kannada.** Same as B7. Vowel signs and virama stay inside.
- **B10 Malayalam.** Chillu letters (U+0D7A to U+0D7F) are letters. The
  older chillu encoding (consonant, virama, ZWJ) is kept as one token. With
  `joiners` = `strip` (default), ZWJ and ZWNJ are removed after
  tokenisation, so the two encodings of the same word come closer; with
  `keep` they are part of the token. In neither mode do they split a word.
- **B11 Gujarati.** Same as B4.
- **B12 Gurmukhi and Odia.** Same as B4. Covered by fixtures but not
  separately named in the description; listed so no Indic script is left
  untested.
- **B13 Arabic and Urdu.** Letters join into tokens across the whole
  word. Harakat (short vowel marks) are marks: kept by default, removed
  with `stripArabicHarakat` on. Tatweel (U+0640) is removed always, as it
  is purely visual. Arabic-Indic and Extended Arabic-Indic digits are
  digits. Right-to-left marks (U+200E, U+200F, U+061C) and bidi controls
  are removed before tokenisation.
- **B14 CJK and other scripts written without spaces.** Han, Hiragana,
  Katakana, Thai, Lao, Khmer and Myanmar text has no spaces between words,
  so a whole sentence is one run. Without help this gives one token per
  title and makes simhash useless (any one-character change flips about
  half the bits). Rule: runs in these scripts are always turned into
  character n-grams over graphemes (default bigrams) regardless of the
  global n-gram setting. Hangul uses spaces and is tokenised as B1.
- **B15 Mixed-script titles.** A token ends where one letter script meets
  another (Latin "IPL" followed directly by Devanagari letters gives two
  tokens). Digits, marks and joiners take the script of the letter before
  them and do not split. "G20 सम्मेलन" gives g20 and सम्मेलन. "COVID-19"
  gives covid and 19 (the hyphen separates).
- **B16 Emoji.** Default `emoji` = `drop`: emoji, including ZWJ sequences,
  skin-tone modifiers, flags and keycaps, are separators. With `keep`,
  each emoji grapheme (the full ZWJ sequence) is one token. The ZWJ inside
  an emoji sequence is never confused with the Indic joiner rule of B10.
- **B17 Digits.** Default `digits` = `fold`: every decimal digit in any
  script (category Nd) maps to ASCII 0 to 9, so "२०२६" and "2026" give the
  same token. Other options: `keep` (no mapping), `drop` (digits removed,
  letters around them kept), `mask` (each digit run becomes one fixed
  placeholder token, so titles that differ only in numbers collide on
  purpose). Non-decimal numbers (Roman numeral letters, circled digits,
  fractions) are handled by NFKC where it applies and are otherwise
  letters or numbers as Unicode says.
- **B18 URLs and e-mail addresses inside titles.** Detected before
  tokenisation by a documented, conservative pattern: a scheme
  (`http`, `https`) followed by `://`, or a token starting `www.`, up to
  the next whitespace; or a local part, `@`, and a dotted domain. Default
  `urls` = `host`: the URL is replaced by its host name without `www.`,
  as one token (dots kept inside it), and the path is dropped. Options:
  `drop` (removed entirely), `keep` (no special handling, the URL splits
  into ordinary tokens). E-mail addresses follow the same setting, with
  `host` meaning the domain part.
- **B19 Unicode whitespace and punctuation.** NBSP, thin and hair spaces,
  ideographic space (U+3000), the danda and double danda (U+0964,
  U+0965) and all punctuation and symbol categories separate tokens.
- **B20 Soft hyphen and invisible format characters.** Soft hyphen
  (U+00AD), word joiner (U+2060), zero-width space (U+200B) and BOM
  (U+FEFF) are removed without splitting the word they sit in, except that
  zero-width space separates (it marks a word break in some Thai and Khmer
  text).
- **B21 Grapheme integrity.** No feature ever begins with a combining mark
  or ends between a virama and the consonant it joins. Character n-grams
  are built over the library's own deterministic grapheme rule (base
  character plus following marks and joiners, with Indic virama joining
  the next consonant into one aksara), not over the platform's grapheme
  break tables, so results do not change when PCRE or ICU are upgraded
  (see 4.13).

### 4.3 Normalisation and its effect

- **B22 Unicode form.** `form` = `NFKC` (default), `NFC` or `none`. NFKC
  maps fullwidth Latin (common in CJK titles), ligatures, superscripts,
  circled digits and compatibility Arabic presentation forms to their
  plain forms, so visually equal titles hash the same. NFC only fixes
  composition differences. `none` hashes code points as given; two titles
  that look identical can then be far apart. The docs show one example of
  each.
- **B23 Case folding.** `caseFold` = `full` (default), `simple` or `off`.
  Full folding is locale-independent: "STRASSE" and "straße" fold the
  same; Turkish dotted capital I folds to i plus combining dot above, not
  to dotless i, and the docs say so. Scripts without case are unchanged.
- **B24 Order.** Normalise, then fold case, then normalise again (NFKC or
  NFC as set), because folding can produce sequences that are not in
  normal form. The order is fixed and documented; it is part of the
  configuration id (4.12).
- **B25 Effect on distance.** For every option above, the test suite holds
  one pair of titles that differ only in that aspect and shows the
  distance with the option on and off.

### 4.4 Stop words

- **B26** Stop words are off by default. The caller can pass one or more
  lists, each tagged with a language code. A token is dropped if it
  appears in any configured list after the same normalisation and folding
  as the input. Lists are matched exactly; no stemming.
- **B27** If removing stop words would leave zero tokens, the stop-word
  step is skipped for that input and the fingerprint is flagged
  (`stopWordsSkipped`). Rationale: "The Who" must not become empty.

### 4.5 Empty and whitespace-only input

- **B28** Empty string, whitespace only, punctuation only, emoji only
  (with `drop`), or any input that leaves zero features gives an *empty*
  fingerprint: value zero, `isEmpty()` true, token count zero. It is a
  sentinel, not a hash.
- **B29** Distance between two fingerprints is always computable, empty or
  not, because it is plain arithmetic. But the near-duplicate check
  returns false (never true) when either side is empty, with reason
  `empty`. This is the guard that would have stopped the production
  incident on its own.
- **B30** A non-empty text whose hash happens to be all zero bits (possible
  in theory) is not empty: `isEmpty()` reports features, not bit values.

### 4.6 Short input and minimum signal

- **B31** The near-duplicate check requires at least `minTokens` word
  tokens (default 3, counted after stop words, before shingles and
  n-grams) on both sides. Below that it returns false with reason
  `too_short`. Rationale: with one or two tokens, the fingerprint is close
  to a random 64-bit value per token and small distances happen by chance
  between unrelated titles.
- **B32** For scripts in B14, `minTokens` counts grapheme bigrams divided
  by two, rounded down, so a short Chinese title is judged on comparable
  signal.

### 4.7 Very long input

- **B33** Up to `maxInputBytes` the cost is linear in input length. A
  title of 4096 bytes hashes inside the budget in section 9. Inputs above
  the cap follow step 3 of 4.1 (`truncate` or `throw`).
- **B34** At most `maxFeatures` features (default 2048) are hashed; beyond
  that the remaining features are ignored in input order and the
  `truncated` flag is set. This bounds memory and time for hostile input.

### 4.8 Shingles and n-grams

- **B35** `wordShingles` = list of sizes, default `[1]` (unigrams only).
  `[1, 2]` adds word bigrams joined by a single space. Shingles never span
  a removed URL or a sentence-ending punctuation mark.
- **B36** `charNgrams` = size (default 0, off). When on, every token is
  also cut into grapheme n-grams of that size with start and end markers;
  tokens shorter than n give one feature, the whole token.
- **B37** `autoCharNgrams` (default on): if an input yields fewer than
  `minTokens` word tokens, character trigrams are added for that input so
  that very short or agglutinative titles (Tamil, Malayalam, Telugu
  compounds) still produce a stable fingerprint. The flag on the
  fingerprint says this happened. The near-duplicate check still applies
  B31: auto n-grams improve the hash, not the confidence rule.
- **B38** Default weights: unigram 1.0, word shingle 1.0, character n-gram
  0.25 (so many n-grams do not drown the words). All configurable.

### 4.9 Token hash function

- **B39** Default token hash: XXH64 with seed 0, as provided by PHP's
  built-in `hash` extension since 8.1. Chosen because it is built in
  (zero dependencies), fast, well mixed in all 64 bits, specified and
  stable across platforms, and has a published reference. It is not a
  cryptographic hash and does not need to be: the input is public text.
- **B40** Alternative `md5-64`: the first 64 bits (first 16 hex digits) of
  MD5 of the UTF-8 feature bytes. Offered for compatibility with existing
  stored fingerprints that were built this way, which is a common pattern
  in older PHP code. Slower, same quality for this purpose.
- **B41** A caller may supply a token hasher that returns exactly 8 bytes.
  Anything else raises `ConfigException` on first use.
- **B42** Byte and bit order is fixed: the 8 bytes are read big-endian;
  bit 63 (most significant) is the first bit of the first hex digit.

### 4.10 Weighting

- **B43** Each occurrence of a feature adds its weight to the 64 bit sums
  (plus where the feature hash bit is 1, minus where it is 0). Repeated
  tokens therefore count more, by default.
- **B44** `weights` may be a map from normalised token to weight, or a
  callback taking the normalised token, its kind (word, shingle, n-gram)
  and its position, returning a float. Weights must be finite and
  non-negative; a negative, NaN or infinite weight raises
  `ConfigException` naming the token. Weight zero removes the feature.
- **B45** If all weights are zero the fingerprint is empty (B28).

### 4.11 Reduction and representations

- **B46** A bit is 1 when its weighted sum is strictly greater than zero,
  else 0. Ties give 0. Documented, tested.
- **B47** `hex()` gives 16 lowercase hex digits, zero padded. Parsing
  accepts upper or lower case, rejects anything else (wrong length,
  non-hex, `0x` prefix) with `InvalidInputException`.
- **B48** `toInt()` gives the signed 64-bit PHP integer with the same bit
  pattern (values with bit 63 set are negative). Parsing from int accepts
  any PHP int on 64-bit builds.
- **B49** `toUnsignedString()` gives the unsigned decimal value as a
  string (0 to 18446744073709551615), suitable for an unsigned 64-bit
  database column. Parsing from that string is supported and validated.
- **B50** `bits()` gives a 64-character string of 0 and 1, most
  significant first, for debugging.
- **B51** Round trips hex to int to unsigned string to hex are identity
  for every value, including 0, 1, 2^63 and 2^64 - 1.

### 4.12 Distance, near-duplicates and batches

- **B52** `distance(a, b)` returns the Hamming distance 0 to 64. It
  accepts fingerprints, hex strings, ints or unsigned strings, and is
  symmetric. Same input gives 0.
- **B53** `isNearDuplicate(a, b, threshold)` is true when both sides pass
  B29 and B31 and the distance is at most `threshold` (default 6, the
  value used in production for titles of three or more content words).
  It also returns a reason: `near`, `far`, `empty`, `too_short`,
  `config_mismatch`. Threshold must be an int from 0 to 64, else
  `ConfigException`.
- **B54** Each fingerprint carries a configuration id: a short stable
  string derived from every setting that changes the bits (hash function,
  form, folding, digits, URLs, emoji, joiners, shingles, n-grams, weights
  identity, library algorithm version). Comparing fingerprints with
  different ids through `isNearDuplicate` returns false with reason
  `config_mismatch`. Raw `distance` still works, by design, for callers
  who parse stored hex.
- **B55** `findNearPairs(list, threshold)` takes an array of texts or
  fingerprints with any keys and returns every pair (keyA, keyB,
  distance) with distance at most threshold, keyA before keyB in input
  order, sorted by distance then input order. Empty and too-short items
  never pair. Exact: no false negatives within the threshold.
- **B56** For lists above a size cut-over (default 2000 items), the batch
  helper uses a pigeonhole band index: split 64 bits into threshold + 1
  bands, bucket by each band, compare only items that share a bucket. The
  result is identical to the brute-force result; a test proves this on a
  random corpus.
- **B57** `groupNearDuplicates(list, threshold)` returns groups by
  single-link union of near pairs. The docs warn that single link chains:
  A near B and B near C puts A and C together even if they are far apart.
  An option `maxGroupDiameter` rejects joins that would put two members
  further apart than the threshold (complete link, slower).
- **B58** Duplicate texts in a batch give distance 0 pairs, not an error.

### 4.13 Platform, extensions and determinism

- **B59 64-bit builds.** On 64-bit PHP (`PHP_INT_SIZE` 8) the library uses
  native ints for bit sums and popcount.
- **B60 32-bit builds.** On 32-bit PHP, hashing, hex, unsigned strings and
  distance all work by handling the fingerprint as two 32-bit halves (or
  four 16-bit lanes where sign handling needs it); results are bit for bit
  identical to 64-bit builds. `toInt()` and parsing from int raise
  `UnsupportedPlatformException` with a message that says a 64-bit build
  is needed for integer form. CI cannot easily run 32-bit PHP, so a test
  forces the 32-bit code path on a 64-bit build and compares outputs.
- **B61 mbstring.** Required (declared in `composer.json` as
  `ext-mbstring`). Used for case folding and length handling. If missing
  without Composer, the bootstrap raises `MissingExtensionException` on
  first use with a clear message.
- **B62 intl.** Needed for `NFC` and `NFKC` (Normalizer). Suggested, not
  required, in `composer.json`. If intl is missing and the configured form
  is not `none`, constructing the hasher raises
  `MissingExtensionException` (fail closed). Reason: silently skipping
  normalisation on one host would make its fingerprints incomparable with
  another host's while looking valid. Callers who accept that choose
  `form` = `none` explicitly, and the configuration id records it.
- **B63 pcre.** Uses PCRE with the `u` flag and Unicode properties, part
  of every PHP build. No reliance on `\X` or on PCRE grapheme tables
  (B21).
- **B64 Determinism.** Same input, same configuration, same library
  version: same fingerprint on every supported PHP version, OS, CPU and
  word size. The test suite pins golden fingerprints for the fixture
  corpus and runs them on PHP 8.1, 8.2 and 8.3. Limit, stated in the docs:
  Unicode tables in mbstring, intl and PCRE follow the PHP and ICU
  versions, so characters assigned in a newer Unicode version than the
  oldest supported runtime may tokenise differently across hosts. The
  golden corpus avoids such characters and a test documents the
  boundary. Any change in output for the same configuration is a breaking
  change and bumps the algorithm version inside the configuration id.

### 4.14 Failure modes summary

| Situation | Result | Open or closed |
|---|---|---|
| Invalid UTF-8 | `InvalidInputException` (or scrub if opted in) | Closed |
| Zero features | Empty fingerprint, never near anything | Closed |
| Below `minTokens` | Hash returned, near check false | Closed |
| Over `maxInputBytes` | Truncate and flag, or throw | Configurable |
| intl missing, form set | `MissingExtensionException` | Closed |
| mbstring missing | `MissingExtensionException` | Closed |
| 32-bit build, int form | `UnsupportedPlatformException` | Closed |
| Bad config value | `ConfigException` at construction | Closed |
| Bad custom weight or hasher output | `ConfigException` on first use | Closed |
| Config ids differ | Near check false, reason given | Closed |

Closed throughout because a wrong "near" answer silently merges unrelated
items, which is worse than a missed match.

### 4.15 Limits of simhash, stated honestly

- **B65** Very short texts: with fewer than about five tokens, one changed
  word moves the fingerprint a long way and unrelated texts can land close
  by chance. The minimum-signal guard reduces false matches; it does not
  create recall.
- **B66** Numeric-heavy titles: "Sensex up 312 points" and "Sensex down 145
  points" share most tokens and land close. `digits` = `mask` makes them
  identical on purpose; `keep` makes numbers count as tokens, still a
  minority. Callers who care about numbers must compare them separately.
- **B67** Paraphrase and translation are not detected (non-goal).
- **B68** Boilerplate prefixes such as "Live updates:" or outlet names in
  titles pull unrelated titles together. Stop-word lists or weight maps
  are the remedy; the README shows how.

## 5. Public interface

Proposed shape. Final names in `docs/ARCHITECTURE.md`.

- `Simhash` (final class): built from a `Config`. Methods: hash a text to a
  `Fingerprint`; explain a text (section 11); distance; near-duplicate
  check returning a `Match` result (bool plus reason plus distance).
  Static convenience: hash with defaults, distance of two hex strings.
- `Config` (immutable, with named-argument constructor and `with*`
  methods): every setting in section 7. Validates on construction.
  Exposes the configuration id.
- `Fingerprint` (immutable value object): `hex()`, `toInt()`,
  `toUnsignedString()`, `bits()`, `isEmpty()`, `tokenCount()`,
  `featureCount()`, flags (`truncated`, `autoNgrams`, `stopWordsSkipped`),
  `configId()`. Static parsers from hex, int and unsigned string. Equality
  by value and config id.
- `Batch`: `findNearPairs` and `groupNearDuplicates` (B55 to B57).
- `Tokenizer` and `Normalizer`: public so callers can inspect tokens, but
  marked `@internal`-stable only in the sense that output is covered by
  determinism tests.
- `TokenHasher` interface plus two implementations (XXH64, MD5-64).
- `StopWords`: holds caller-supplied lists keyed by language code; loads
  from an array or a plain text file of one word per line (UTF-8,
  `#` comments).
- Exceptions, all implementing one package marker interface
  `SimhashException`: `InvalidInputException`, `ConfigException`,
  `MissingExtensionException`, `UnsupportedPlatformException`.
- Bootstrap file `src/bootstrap.php` for use without Composer.

## 6. Data

Nothing is stored. No files, no caches on disk, no network. Optional
in-memory static tables (digit maps, script ranges) are immutable. Callers
store fingerprints themselves; the README recommends storing hex or the
unsigned decimal string together with the configuration id, and re-hashing
from source text when the id changes. Nothing to uninstall.

## 7. Configuration and defaults

| Setting | Default | Values |
|---|---|---|
| `hasher` | XXH64 | XXH64, MD5-64, custom |
| `form` | NFKC | NFKC, NFC, none |
| `caseFold` | full | full, simple, off |
| `foldLatinDiacritics` | off | on, off |
| `stripArabicHarakat` | off | on, off |
| `joiners` | strip | strip, keep |
| `apostrophe` | join | join, split |
| `digits` | fold | fold, keep, drop, mask |
| `urls` | host | host, drop, keep |
| `emoji` | drop | drop, keep |
| `stopWords` | none | lists by language code |
| `wordShingles` | [1] | list of sizes 1 to 4 |
| `charNgrams` | 0 | 0, or 2 to 5 |
| `autoCharNgrams` | on | on, off |
| `weights` | 1.0 word, 1.0 shingle, 0.25 n-gram | map or callback |
| `minTokens` | 3 | 0 to 32 |
| `threshold` | 6 | 0 to 64 |
| `maxInputBytes` | 4096 | 64 to 1048576 |
| `onLongInput` | truncate | truncate, throw |
| `maxFeatures` | 2048 | 16 to 65536 |
| `scrub` | off | on, off |
| `batchIndexCutover` | 2000 | 0 (always index) upward |

Defaults are chosen for news titles in Indian languages and English.

## 8. Security and privacy

- Treats input as untrusted: validates UTF-8, caps input bytes and
  features, never uses `eval`, shell, the `@` operator, or dynamic regex
  built from input. Stop words are matched by array lookup, never put into
  a regex.
- Regex patterns are fixed and checked for catastrophic backtracking; a
  test feeds adversarial input (long runs of marks, joiners, digits,
  repeated `www.`) and asserts the time budget.
- Custom callbacks run in the caller's trust domain; exceptions from them
  propagate unchanged.
- Simhash leaks information: similar texts have similar fingerprints, and
  short texts can be guessed by brute force from a fingerprint. The docs
  say: do not treat a fingerprint as an anonymised form of private text.
- No logging, so no text is written anywhere by the library.

## 9. Performance budget

Measured on one core of a 2020s x86-64 server, PHP 8.3, no JIT, opcache on.
Targets for the builder; a benchmark script records actuals in the README.

- Hash one 80-character title, defaults: median under 50 microseconds.
- Hash one 4096-byte input: under 2 milliseconds.
- Distance of two fingerprints: under 1 microsecond on 64-bit.
- `findNearPairs` over 10,000 fingerprints, threshold 6: under 2 seconds
  with the band index, memory under 64 MB.
- No allocation that grows faster than linearly with input length.
- 32-bit path may be up to five times slower; documented.

## 10. Compatibility

- PHP 8.1, 8.2, 8.3 (CI matrix), 64-bit primary; 32-bit supported per B60.
- Extensions: mbstring and pcre required; intl suggested and required for
  NFC or NFKC; hash (built in) for XXH64 and MD5.
- Linux, macOS, Windows. No reliance on locale (`setlocale` has no effect
  on output; a test changes locale and checks).
- Frameworks: none needed. Works in WordPress, Laravel, Symfony or plain
  PHP.
- Fingerprints are portable between hosts only when the configuration id
  matches (B54) and both hosts meet B62.

## 11. Observability

- No logging inside the library.
- `explain(text)` returns a structured report: the normalised text, each
  token with its script, each feature with kind, weight and 64-bit hash,
  the 64 bit sums, the final hex, flags, configuration id, and which
  extension versions (PCRE, ICU, Unicode version of mbstring) were seen.
  An operator debugging "why did these two titles match" prints both
  explanations side by side.
- `Match` results carry a reason code so callers can count `empty`,
  `too_short` and `config_mismatch` outcomes in their own metrics. A spike
  in `empty` is the early warning that a script is not being tokenised.
- A small CLI script under `bin/` (dev only, not autoloaded) hashes lines
  from stdin and prints hex, token count and flags, for quick checks.

## 12. Acceptance tests

PHPUnit 10. Each test names the cases it covers. Fixture files live in
`tests/fixtures/` (see the corpus plan after the table).

| Test | Covers | Asserts |
|---|---|---|
| T1 Regression: ASCII-only failure | B4, B7, B8, B28, B29 | Three unrelated titles, one each in Devanagari, Telugu and Tamil, give non-empty fingerprints with token count above zero, pairwise distance above the threshold, and none is a near duplicate. A reference ASCII-only tokeniser in the test (a few lines, test-only) shows the old behaviour: all three empty, all at distance 0. |
| T2 Latin tokens | B1 | Exact token list for an English title. |
| T3 Latin diacritic folding | B2 | Tokens with folding off and on. |
| T4 Apostrophe and hyphen | B3 | join and split modes; hyphen splits. |
| T5 Devanagari marks | B4, B21 | Token list equals expected words; no token starts with a mark. |
| T6 Nukta equivalence | B5 | Precomposed and decomposed forms equal under NFC and NFKC; documented difference under none. |
| T7 Bengali | B6 | Token count and khanda ta handling. |
| T8 Telugu | B7 | Token count equals word count; marks kept. |
| T9 Tamil | B8 | Pulli kept; Tamil digits folded. |
| T10 Kannada | B9 | Token count; marks kept. |
| T11 Malayalam chillu | B10 | Atomic and ZWJ chillu forms: same token with strip, distinct but unsplit with keep. |
| T12 Gujarati, Gurmukhi, Odia | B11, B12 | Token counts for one fixture title each. |
| T13 Arabic and Urdu | B13 | Harakat on and off; tatweel removed; RLM removed; Arabic-Indic digits folded. |
| T14 No-space scripts | B14, B32 | Chinese, Japanese and Thai titles produce bigram features; a one-character edit gives a small distance; Hangul splits on spaces. |
| T15 Mixed script | B15 | Script boundary split; G20 and COVID-19 cases. |
| T16 Emoji | B16 | drop and keep; ZWJ family and flag sequences as one token in keep. |
| T17 Digits | B17 | fold, keep, drop, mask on Devanagari, Tamil, Arabic-Indic and ASCII digits. |
| T18 URLs and e-mail | B18 | host, drop, keep for http, https, www and e-mail forms. |
| T19 Whitespace and punctuation | B19 | NBSP, U+3000, danda separate tokens. |
| T20 Invisible characters | B20 | Soft hyphen, word joiner, BOM removed without split; ZWSP splits. |
| T21 Grapheme n-grams | B21, B36 | No n-gram starts with a mark or ends after a virama, over every Indic fixture. |
| T22 Normalisation forms | B22, B25 | Fullwidth and ligature pairs: equal under NFKC, different under none. |
| T23 Case folding | B23, B25 | ß and SS; Turkish İ behaviour; off mode. |
| T24 Normalise-fold-normalise order | B24 | A sequence that leaves normal form after folding hashes the same as its normalised twin. |
| T25 Stop words | B26 | Tokens dropped per list; matching after folding. |
| T26 Stop-word fallback | B27 | All-stop-word title is not empty; flag set. |
| T27 Empty inputs | B28 | Empty, spaces, punctuation, emoji-only give empty fingerprints. |
| T28 Empty never matches | B29 | Two empty fingerprints: distance 0, near check false with reason empty. |
| T29 Zero bits but not empty | B30 | Custom hasher that forces all-zero output: isEmpty false. |
| T30 Minimum signal | B31, B65 | Two-token titles never near; three-token near pair matches. |
| T31 Long input | B33, B34, 4.1 step 3 | Truncation at grapheme boundary with flag; throw mode; feature cap. |
| T32 Invalid UTF-8 | 4.1 step 1 | Exception with offset; scrub mode hashes. |
| T33 Control characters | 4.1 step 2 | NUL and C1 separate tokens. |
| T34 Shingles | B35 | Bigram features present; no shingle across a removed URL. |
| T35 Auto n-grams | B37 | Short Tamil compound title gets n-grams and flag; near check still needs minTokens. |
| T36 Default weights | B38, B43 | Repeated token shifts bit sums by its count; n-gram weight 0.25. |
| T37 XXH64 reference | B39, B42 | Feature hashes match published XXH64 test vectors; byte order. |
| T38 MD5-64 compatibility | B40 | Fingerprint equals a reference computed in the test from first 16 hex of MD5 per token. |
| T39 Custom hasher | B41 | Wrong length raises ConfigException. |
| T40 Weight validation | B44, B45 | Map and callback; negative, NaN, INF rejected; all-zero weights give empty. |
| T41 Tie rule | B46 | Two features with opposite bits and equal weight give 0 in those bits. |
| T42 Representations | B47 to B51 | Hex parsing rules; int sign; unsigned string; bits; round trips on 0, 1, 2^63, 2^64 - 1. |
| T43 Distance | B52 | Symmetry, identity, 64 for complements, mixed input types. |
| T44 Near check | B53 | Threshold bounds; reasons near and far; invalid threshold. |
| T45 Config id | B54 | Changing any bit-affecting setting changes the id; near check reports config_mismatch. |
| T46 Near pairs, brute force | B55, B58 | Expected pairs on a small corpus; keys preserved; duplicates at 0. |
| T47 Near pairs, band index | B56 | Index result equals brute force on 5,000 random fingerprints at thresholds 0, 3, 6, 10. |
| T48 Grouping | B57 | Chain A-B-C joins under single link; split under maxGroupDiameter. |
| T49 64-bit path | B59 | Popcount equals nibble-table popcount on random values. |
| T50 32-bit path | B60 | Forced 32-bit code path equals 64-bit output on the whole corpus; toInt raises UnsupportedPlatformException when forced. |
| T51 Extension checks | B61, B62 | With a seam that reports intl missing: NFKC config throws; none config works and id differs. |
| T52 No grapheme tables | B63, B21 | Aksara segmentation of fixtures matches the library rule regardless of PCRE version (checked against stored expected segments). |
| T53 Golden corpus | B64 | Every fixture title hashes to its stored golden hex under defaults and two other configs; runs in CI on 8.1, 8.2, 8.3. |
| T54 Locale independence | 10 | Output unchanged after setlocale to tr_TR and C. |
| T55 Numeric-heavy limit | B66 | Documented pair lands near under keep; identical under mask. |
| T56 Paraphrase limit | B67, B68 | Documented pair of same-meaning titles is far; boilerplate prefix pulls unrelated titles closer, reduced by stop words. |
| T57 Adversarial input | 8 | Long runs of marks, joiners, digits and repeated www. finish within budget. |
| T58 Performance smoke | 9 | Benchmark script runs; CI asserts a loose upper bound only. |
| T59 Explain | 11 | Report fields present and consistent with the fingerprint. |
| T60 Bootstrap without Composer | 5 | Requiring the bootstrap file alone hashes a title. |

### Fixture corpus plan

- Only self-written titles, or text that is clearly public domain (for
  example a sentence from a work whose author died before 1955, with the
  source named). No scraped headlines, no copied news titles, no titles
  from any live site.
- At least 12 titles per script for Latin (English), Devanagari (Hindi and
  Marathi), Bengali, Telugu, Tamil, Kannada, Malayalam, Gujarati,
  Gurmukhi, Odia, Arabic script (Urdu and Arabic), Chinese, Japanese,
  Thai and Korean, plus 12 mixed-script titles. Each script set holds
  near pairs (one-word edits, reordered words, added prefix) and far
  pairs (unrelated topics).
- Written or checked by a reader of the language; the contributor and a
  CC0 dedication are recorded per file in a header.
- Files are UTF-8 JSON lines: id, language code, script, text, and for
  pair files the expected relation (near or far).
- Golden fingerprints are generated once by the builder, reviewed by eye
  against `explain` output, and committed. Regenerating them is a
  deliberate, reviewed act tied to an algorithm version bump.

## 13. Open questions

For the builder to answer in `docs/ARCHITECTURE.md`.

1. Default hasher: XXH64 (proposed) or MD5-64 for drop-in compatibility
   with older stored fingerprints? Measure both.
2. Is a library-defined aksara rule (B21) good enough for all Indic
   scripts, or should segmentation use intl's grapheme iterator when
   present, at the cost of determinism across ICU versions?
3. Should 0.1.0 ship any stop-word lists (for example short English and
   Hindi lists written from scratch), or only the loader?
4. Is fail-closed on missing intl (B62) too strict for shared hosts? An
   alternative is a built-in NFC table for Indic scripts only.
5. Auto n-gram trigger and n-gram weight: are fewer than three tokens and
   weight 0.25 right? Tune on the fixture corpus and report.
6. Band index cut-over and band layout for thresholds above 10, where the
   pigeonhole bands get narrow and buckets large.
7. Should the near-duplicate threshold scale with token count (stricter
   for short titles), instead of one fixed number?
8. Which Unicode version is the floor for the golden corpus, given the
   ICU and PCRE versions bundled with PHP 8.1?

## 14. Lessons from production

- **L1.** Never tokenise with an ASCII-only character class. Use Unicode
  letter, number and mark properties, and test with at least three
  non-Latin scripts.
- **L2.** A text with no tokens has no fingerprint. Return a sentinel that
  says so, and never let it match anything. All-zero output that looks
  like a real hash is the most dangerous result a hash function can give.
- **L3.** Require real signal on both sides before trusting a near match.
  In production the near-duplicate shortcut only fires when both titles
  have at least three content words.
- **L4.** Combining marks are part of the word. Accent stripping written
  for Latin text must never be applied to Indic vowel signs, virama or
  nukta.
- **L5.** A regex over invalid UTF-8 fails, and failure looks like "no
  tokens". Check for failure explicitly and raise.
- **L6.** A near-identical title is one signal, not proof. Repeats from the
  same source need stricter rules than matches across sources; leave room
  for callers to add them.
- **L7.** Fingerprints made with different settings are not comparable.
  Store the configuration id with the hash and re-hash when it changes.
- **L8.** Silent wrong answers are worse than errors. Every failure mode in
  this library raises or returns an explicit reason.
- **L9.** Test the bug you fixed with the input that caused it: the
  regression test (T1) must fail against an ASCII-only tokeniser.
