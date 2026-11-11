# Architecture

This document maps the code to the PRD (`docs/PRD.md`), shows the data flow,
and records every design decision, including the answers to the PRD's open
questions (section 13). Section and case numbers (B1, T1) refer to the PRD.

## Modules

All classes live in `src/` under `IndianOpinion\Simhash`. Classes marked
internal may change without notice; everything else is public API.

| Class | Role |
|---|---|
| `Simhash` | Entry point. Hashes a text to a `Fingerprint`, explains a text, computes distance, runs the near-duplicate check. Static helpers `fingerprint()` and `hexDistance()`. |
| `Config` | Immutable settings with named-argument constructor and `with*()` methods. Validates on construction. Builds the configuration id. |
| `Fingerprint` | Immutable value: 8 bytes plus configuration id, token count, feature count and flags. Hex, signed int, unsigned decimal string, bits, `toArray()`, and parsers for each. |
| `MatchResult` | Result of `isNearDuplicate`: `isNear`, `reason`, `distance`, `threshold`. (PRD calls it `Match`; `match` is a reserved word in PHP 8.) |
| `Batch` | `findNearPairs()` and `groupNearDuplicates()` over a keyed list of texts or fingerprints. |
| `BandIndex` | Four 16-bit bands. Streaming `add()` and `query()`, and batch `pairs()`. |
| `NearPair` | One result of `findNearPairs()`. |
| `Hasher` | Interface: `hash(string): string` (8 bytes) and `id(): string`. |
| `Xxh64Hasher` | Default hasher: XXH64, seed 0, from PHP's `hash()`. |
| `StopWords` | Caller-supplied lists by language code, from arrays or one-word-per-line files. |
| `Tokenizer` | Text to `Token` list: cleaning, forms, URLs, emoji, letter runs, script boundaries, bigrams for scripts without spaces. Public for inspection. |
| `TextNormalizer` | Character-level steps: UTF-8 checks, scrub, truncate, invisible characters, forms, diacritics, harakat, case folding, apostrophes, digits. Public for inspection. |
| `Token` | One token: text, kind (`word`, `cjk`, `url`, `emoji`), script, segment. |
| `Graphemes` | Internal. Grapheme segmentation with the aksara rule. |
| `BitCounter` | Internal. Per-weight bit counts, bit-sliced on 64-bit builds. |
| `Bits` | Internal. Popcount, distance, hex, int and unsigned conversions on 8-byte strings, safe on 32-bit builds. |
| `Platform` | Internal. Capability checks (intl, mbstring, word size) with a test seam. |
| `Exception\*` | `SimhashException` marker interface; `InvalidInputException`, `ConfigException`, `MissingExtensionException`, `UnsupportedPlatformException`. |

`bootstrap.php` at the repository root registers an autoloader for the
package namespace only, for use without Composer. (The PRD proposed
`src/bootstrap.php`; the root is easier to find and keeps `src/` pure PSR-4.)
`bin/simhash` and `bin/benchmark.php` are development tools, not autoloaded.

## Data flow

```
text
 |  Simhash::run
 |-- UTF-8 check (throw with byte offset, or scrub to U+FFFD)
 |-- length cap (truncate at a grapheme boundary and flag, or throw)
 v
Tokenizer::tokenize
 |-- clean: controls to spaces; soft hyphen, word joiner, BOM, bidi, tatweel removed; ZWSP to space
 |-- form (NFKC/NFC/none)
 |-- Latin diacritic folding (opt-in), harakat stripping (opt-in)
 |-- cut out URLs and e-mails  -> host token, or a dropped segment break
 |-- cut out emoji sequences   -> emoji token, or a separator
 |-- per remaining piece: case fold, form again, apostrophes, digits
 |-- split into segments at sentence ends (shingles never cross them)
 |-- letter runs: [L N][L N M ZWJ ZWNJ]*, trailing joiner kept only after a mark
 |-- split runs at script changes (Common and Inherited letters, digits, marks inherit)
 |-- no-space scripts -> grapheme bigrams (kind cjk); others -> word tokens
 |-- joiners = strip: old Malayalam chillu to atomic, then ZWJ and ZWNJ removed
 v
tokens -> stop words (skipped and flagged if they would empty the text)
       -> token count = words + floor(cjk bigrams / 2)
       -> features: unigrams, shingles, explicit n-grams, auto trigrams
       -> cap at maxFeatures (flag)
       -> weight (kind default, map, callback; validated)
       -> hash (8 bytes, checked) -> BitCounter per distinct weight
       -> sums = weight * (2 * ones - n), bit = sum > 0
 v
Fingerprint (bytes, config id, token count, feature count, flags)
```

`explain()` runs the same path and keeps every intermediate value.

## Decisions on the PRD's open questions

### 1. Default hasher

XXH64 through PHP's `hash()` (built in since 8.1), behind the small `Hasher`
interface so a caller can plug in another. There is no built-in MD5-64 mode:
XXH64 is faster, has published test vectors, and the PRD's compatibility case
(B40) is met by a five-line `Hasher` that the README shows and the test suite
uses (T38 reproduces the MD5-64 fingerprint through the interface). The
hasher's `id()` is part of the configuration id.

### 2 and 4. Graphemes and missing intl

When intl is loaded, grapheme clusters come from ICU (`grapheme_extract`).
Without it, a built-in rule keeps every combining mark (Mn, Mc, Me), ZWJ,
ZWNJ and variation selector attached to the character before it. The rule
also covers the letters that Unicode's grapheme rules treat specially and
that can appear inside a token: Thai and Lao sara am (spacing marks), the
halfwidth katakana sound marks (extend), prepended letters such as the
Malayalam dot reph (GB9b), and conjoining Hangul jamo (GB6 to GB8). A test
hashes the whole corpus with `form: none` through both paths and requires the
same bits, because with `form: none` the configuration id is the same on both
hosts. One known gap remains: a precomposed Hangul syllable with a final
consonant followed by a lone vowel jamo, which ICU splits and the built-in
rule keeps together. NFKC composes such input, so it only matters with
`form: none`. On top of
both, one library rule merges a cluster that ends in a joining virama with a
following letter, so a conjunct is one aksara. ICU 74 (Unicode 15.1, rule
GB9c) already does this for Devanagari, Bengali, Gujarati, Odia, Telugu and
Malayalam; older ICU does not. The extra rule makes the two paths and all ICU
versions agree. `tests/fixtures/aksara.json` stores the expected segments for
every word of every Indic fixture, and a test checks both paths against it
(T52).

Joining viramas: Devanagari, Bengali, Gurmukhi, Gujarati, Odia, Telugu,
Kannada and Malayalam. Tamil pulli and Sinhala al-lakuna are left out on
purpose: in those scripts the dead consonant is written as its own visible
letter, and merging would make n-grams of short Tamil compounds much less
useful (measured: a Tamil case-ending change gave distance 27 with merging
and 12 without).

Missing intl never hard-fails. NFC, NFKC and the decomposition step of Latin
diacritic folding are skipped; every fingerprint carries the
`normalisation_unavailable` flag; and the configuration id gets the suffix
`-nonorm`. This replaces the PRD's fail-closed rule (B62) with a softer one
that keeps the same safety property: fingerprints from a host without intl
never pass `isNearDuplicate` against fingerprints from a host with it
(`config_mismatch`), so a silent difference cannot merge unrelated items.
mbstring stays required: without it the constructor raises
`MissingExtensionException`.

### 3. Stop words

The package ships no lists. `StopWords` accepts arrays or one-word-per-line
UTF-8 files from the caller. Lists are normalised and folded through the same
pipeline as the input, matched by array lookup, and their content feeds the
configuration id.

### 5. Auto character n-grams

Character trigrams switch on automatically when a text yields fewer than
three word tokens (fixed, `Config::AUTO_NGRAM_BELOW`), with weight 1
(`autoNgramWeight`). `autoCharNgrams` is `auto` by default, and `on` or `off`
force it. Weight 1 rather than the PRD's 0.25: with fewer than three tokens
the words carry almost no signal, and on the fixture corpus the trigrams only
stabilise the fingerprint when they are not drowned by the words. Explicit
`charNgrams` keep the PRD default of 0.25. Auto trigrams are not added when
explicit `charNgrams` is already 3. Auto n-grams never change the
minimum-signal rule (B37): `tokenCount()` counts words only.

### 6. Near-pair search

- `Batch::findNearPairs(..., method: 'brute')` compares every pair. Exact for
  any threshold. Quadratic.
- `method: 'index'` uses `BandIndex`: four bands of 16 bits. Two fingerprints
  within distance 3 share a band exactly (pigeonhole), so the index is exact
  up to 3. From 4 to 16 it is a candidate filter: every candidate is verified
  by Hamming distance, so there are no false positives, but some true pairs
  are missed. Above 16 it refuses with `ConfigException`; use brute force.
- `method: 'auto'` (default) uses the index only when it is exact (threshold
  3 or less and at least `batchIndexCutover` items) and brute force
  otherwise, so `auto` is always exact.

T47 checks on 5,000 random fingerprints with 600 planted near copies: the
index equals brute force at thresholds 0 and 3; at 6 and 10 it is a subset of
brute force and contains every pair up to 3.

This departs from B56 (an index that is always exact): a pigeonhole index
with `threshold + 1` bands gets narrow, high-collision buckets at threshold 6
and above and is often no faster than brute force in PHP. The PRD's
performance budget for 10,000 items at threshold 6 (2 seconds) is not met by
brute force on the test machine (17 seconds, see README); it is met at
threshold 3 with the index (0.1 seconds).

### 7. Threshold

A fixed default of 6, not scaled by token count. The minimum-signal rule
(`minTokens`, default 3) already refuses short titles outright, which is
simpler to reason about than a sliding threshold and has no edge cases at the
boundaries. Production used 6 for titles of three or more content words.

### 8. Unicode floor

Fixtures are self-written. No Unicode version is pinned beyond what PHP 8.1's
bundled PCRE provides. The corpus uses only characters that have been in
Unicode for many years, so the golden fingerprints hold on PHP 8.1, 8.2 and
8.3. `explain()` reports the PCRE, ICU and Unicode versions seen on the host.

## Other decisions

- **Integer counting.** Bit sums are computed as `weight * (2 * ones - n)` per
  distinct weight, from integer counts, in a fixed order. This makes the
  result exact for the usual weights and identical on 32-bit and 64-bit
  builds. On 64-bit builds the counts are bit-sliced (a ripple-carry add
  across count planes), and when only one weight is in play the fingerprint
  is read straight from the planes with a bit-sliced comparison against
  `n / 2`. A test checks that this fast path matches the detailed path on the
  whole corpus.
- **32-bit support (B60).** Fingerprints are held as 8 raw bytes. Distance,
  hex and unsigned decimal conversions work on 16-bit lanes, so no
  intermediate value exceeds 2^31. `Platform::simulate()` lets the tests force
  the 32-bit path on a 64-bit build (T50).
- **Malayalam chillu (B10).** With `joiners: strip`, the older encoding
  (consonant, virama, ZWJ) is mapped to the atomic chillu letter before the
  joiners are removed, so both encodings give the same token, as T11 asks.
- **Script groups.** Han, Hiragana, Katakana and Bopomofo form one group, so a
  Japanese title is one run of bigrams rather than a split at every kana.
  Digit runs inside a no-space run become their own word tokens.
- **Segments.** Shingles do not cross `!`, `?`, danda, double danda, `。`,
  `！`, `？`, Arabic question mark and full stop, ellipsis, a full stop
  followed by space or end of text, or a dropped URL. A dot inside a word or a
  host name is not a break.
- **Feature namespaces.** Character n-grams are hashed with a `#` prefix so an
  n-gram never collides with a word token of the same text. Shingles contain a
  space and cannot collide with tokens.
- **Weight callback signature.** `fn(string $feature, string $kind, int
  $position, float $default)`. Kinds are `word`, `shingle` and `ngram`. The
  position is the index of the first token. A callback cannot be identified
  by value, so the caller must name it with `weightsId` for the
  configuration id; a callback without one raises `ConfigException`, since
  two different callbacks would otherwise share an id and compare as equal.
- **Exact weights.** Weights enter the configuration id with six decimals
  when that is exact and as their IEEE 754 bytes otherwise, and bit counters
  are grouped by the exact float. Neither depends on the `precision` or
  `serialize_precision` ini settings.
- **Hostile input stays linear.** UTF-8 validation and scrubbing scan in
  bounded steps, so neither the result nor the cost depends on PCRE's JIT or
  backtrack limit. Truncation walks back at most 64 code points to find a
  grapheme boundary and otherwise cuts at a code point boundary. The e-mail
  pattern only starts where a local part can start. The test suite feeds
  near-1 MB hostile inputs with the largest allowed cap.
- **Near check order.** `empty`, then `config_mismatch` (an empty id, as on a
  bare parsed fingerprint, counts as a mismatch), then `too_short` (a parsed
  fingerprint without a token count counts as too short when `minTokens` is
  above zero), then `near` or `far`.
- **Distance input types.** A 16-character string of hex digits is always
  read as hex; any other string as an unsigned decimal. Pass `Fingerprint`
  objects to avoid the ambiguity.
- **Configuration id.** `shu<algorithm version>-<12 hex digits>` of XXH64 over
  a canonical description of every bit-affecting setting (`Config::describe()`).
  `ALGORITHM_VERSION` is bumped for any change that changes output for the
  same text and settings, together with a reviewed regeneration of
  `tests/fixtures/golden.json` (`php tests/tools/generate-fixtures.php`).

## Test map

Each PRD acceptance test has a test method; the class docblocks name the T
numbers they cover. T38 is met through the `Hasher` interface (decision 1).
T51 tests the decided behaviour (flag and id suffix) instead of B62's
exception. T47 tests the decided index semantics above.
