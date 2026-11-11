# simhash-unicode

64-bit simhash for short text in any script, with Hamming distance and near-duplicate search, for PHP 8.1 and later.
Most PHP simhash code splits on anything that is not `a-z` or `0-9`, so every Hindi, Telugu or Tamil title hashes to zero and "matches" every other one; this library tokenises letters, digits and combining marks in every script, reports empty input as empty, and refuses to call two texts near on too little signal.

Measured result: in production on indianopinion.org, an ASCII-only tokeniser gave every Devanagari, Telugu and Tamil title zero tokens, so all of them hashed to `0000000000000000`, sat at distance 0 from each other, and one story group absorbed six unrelated stories from three outlets.

This project came out of the work on indianopinion.org and is written from scratch; it is independent of and not affiliated with any third-party plugin it integrates with.

## Install

With Composer:

```sh
composer require indianopinion/simhash-unicode
```

Without Composer, download a release and require one file. It registers an autoloader for this package only:

```php
require '/path/to/simhash-unicode/bootstrap.php';
```

Requirements: PHP 8.1 or later, 64-bit recommended, `ext-mbstring` (required), `ext-intl` (strongly recommended, see [Limitations](#limitations)). No runtime dependencies.

## Quick start

```php
use IndianOpinion\Simhash\Simhash;

$simhash = new Simhash();

$a = $simhash->hash('भारत में मानसून समय से पहले पहुँचा, किसान बुवाई की तैयारी में');
$b = $simhash->hash('भारत में मानसून समय से पहले पहुंचा। किसान बुवाई की तैयारी में');

echo $a->hex();          // 6216748d1191a37d
echo $a->tokenCount();   // 12

$match = $simhash->isNearDuplicate($a, $b);
var_dump($match->isNear);   // true
echo $match->reason;        // near
echo $match->distance;      // 5

// Empty input is a sentinel, not a hash, and never matches anything.
$empty = $simhash->hash('!!!');
var_dump($empty->isEmpty());                             // true
echo $simhash->isNearDuplicate($empty, $empty)->reason;  // empty
```

Find near pairs and groups in a list (keys are preserved):

```php
use IndianOpinion\Simhash\Batch;

$batch = new Batch($simhash);
foreach ($batch->findNearPairs($titlesById) as $pair) {
    echo "{$pair->keyA} ~ {$pair->keyB} at {$pair->distance}\n";
}
$groups = $batch->groupNearDuplicates($titlesById);   // list of lists of keys
```

Store fingerprints with their configuration id and token count, so a later comparison can apply the same guards:

```php
$row = $fp->toArray();   // ['hex' => ..., 'config' => 'shu1-...', 'tokens' => 12, 'features' => 12, 'flags' => []]
$fp  = Fingerprint::fromArray($row);
```

The `examples/` directory has two runnable scripts: `basic.php` and `dedupe-feed.php` (streaming dedupe with a band index, then batch grouping).

## Configuration

Build a `Config` with named arguments, or start from the defaults and call `with*()` methods. Every value is validated on construction and a bad one raises `ConfigException`.

```php
use IndianOpinion\Simhash\Config;
use IndianOpinion\Simhash\StopWords;

$config = new Config(
    digits: Config::DIGITS_MASK,
    stopWords: StopWords::fromArray(['en' => ['live', 'updates']]),
);
$simhash = new Simhash($config);
```

| Setting | Default | Values | Changes the bits |
|---|---|---|---|
| `hasher` | `Xxh64Hasher` | any `Hasher` | yes |
| `form` | `NFKC` | `NFKC`, `NFC`, `none` | yes |
| `caseFold` | `full` | `full`, `simple`, `off` | yes |
| `foldLatinDiacritics` | `false` | bool. Strips marks from Latin, Greek and Cyrillic letters only | yes |
| `stripArabicHarakat` | `false` | bool | yes |
| `joiners` | `strip` | `strip`, `keep` (ZWJ and ZWNJ inside words) | yes |
| `apostrophe` | `join` | `join` ("India's" gives `indias`), `split` | yes |
| `digits` | `fold` | `fold` (any script to ASCII), `keep`, `drop`, `mask` (each run becomes `0`) | yes |
| `urls` | `host` | `host` (URL or e-mail becomes its host), `drop`, `keep` | yes |
| `emoji` | `drop` | `drop`, `keep` (each emoji sequence is one token) | yes |
| `stopWords` | none | `StopWords` built from arrays or files | yes |
| `wordShingles` | `[1]` | list of sizes 1 to 4 | yes |
| `charNgrams` | `0` | `0` (off) or 2 to 5 | yes |
| `autoCharNgrams` | `auto` | `auto` (trigrams when a text has fewer than 3 tokens), `on`, `off` | yes |
| `wordWeight`, `shingleWeight` | `1.0` | finite, 0 or more | yes |
| `ngramWeight` | `0.25` | weight of explicit `charNgrams` | yes |
| `autoNgramWeight` | `1.0` | weight of automatic trigrams | yes |
| `weights` | none | map of token to weight, or `fn(string $feature, string $kind, int $position, float $default): float` | yes |
| `weightsId` | `''` | name of a weight callback, for the configuration id. Required with a callback: a closure cannot be identified by value | yes |
| `maxInputBytes` | `4096` | 64 to 1048576 | yes |
| `maxFeatures` | `2048` | 16 to 65536 | yes |
| `onLongInput` | `truncate` | `truncate` (at a grapheme boundary, flagged), `throw` | no |
| `scrub` | `false` | bool. Replace invalid UTF-8 with U+FFFD instead of throwing | no |
| `minTokens` | `3` | 0 to 32 | no |
| `threshold` | `6` | 0 to 64 | no |
| `batchIndexCutover` | `2000` | 0 upward | no |

Settings marked "changes the bits" feed the configuration id, a short string such as `shu1-57555a8696b2` stamped on every fingerprint. When it changes, re-hash from the source text. `isNearDuplicate` returns `config_mismatch` for fingerprints made under different ids; plain `distance()` still works.

### Removing boilerplate

Outlet names and prefixes such as "Live updates:" pull unrelated titles together. Remove them with stop words, or weigh them down:

```php
new Config(stopWords: StopWords::fromFile('en', __DIR__ . '/stop-en.txt'));
new Config(weights: ['live' => 0.0, 'updates' => 0.0]);
```

The package ships no stop-word lists. Words are matched exactly, after the same normalisation and folding as the input. If removing stop words would leave a title empty, they are kept for that title and the `stop_words_skipped` flag is set ("The Who" stays "the who").

### Plugging in another hash

```php
final class Md5Hasher implements \IndianOpinion\Simhash\Hasher
{
    public function hash(string $feature): string { return substr(md5($feature, true), 0, 8); }
    public function id(): string { return 'md5-64'; }
}
new Config(hasher: new Md5Hasher());
```

A hasher must return exactly 8 bytes; anything else raises `ConfigException` on first use. This is how to stay compatible with fingerprints stored by older code that used the first 64 bits of MD5.

## How it works

1. **Validate.** Invalid UTF-8 raises `InvalidInputException` naming the byte offset (a regex over invalid UTF-8 fails, and naive code reads that as "no tokens", which is the zero-hash bug). Inputs over `maxInputBytes` are cut at a grapheme boundary and flagged.
2. **Clean.** Control characters become separators. Soft hyphen, word joiner, BOM, bidi controls and tatweel are removed without splitting the word. Zero-width space separates.
3. **Normalise.** Unicode form (NFKC by default), then case folding (full, locale-independent), then the Unicode form again, because folding can leave text out of normal form.
4. **Cut out URLs, e-mail addresses and emoji** per the settings. Digits are folded to ASCII from any script.
5. **Tokenise.** A token is a run of letters, digits and combining marks, so Indic vowel signs, virama and nukta stay inside the word. ZWJ and ZWNJ count only between two such characters. A token also ends where one letter script meets another (`IPLमैच` gives `ipl`, `मैच`). Han, Hiragana, Katakana, Thai, Lao, Khmer and Myanmar have no spaces, so their runs become grapheme bigrams.
6. **Features.** Each token, plus optional word shingles and character n-grams. Character n-grams are cut on graphemes, and a conjunct (consonant, virama, consonant) is one grapheme, so an n-gram never splits an aksara.
7. **Hash and sum.** Each feature is hashed with XXH64 (seed 0). For each of the 64 bits, the weight is added where the feature hash has a 1 and subtracted where it has a 0.
8. **Reduce.** A bit is 1 when its sum is strictly above zero. Ties give 0. Bit 63 is the first bit of the first hex digit.
9. **Guard.** A text with no features gives an empty fingerprint. `isNearDuplicate` refuses empty sides (`empty`), different configuration ids (`config_mismatch`) and sides with fewer than `minTokens` word tokens (`too_short`) before it looks at the distance (`near` or `far`).

To see every step for one text, print `$simhash->explain($text)`: normalised text, tokens with their script, features with kind, weight and hash, the 64 sums, flags, configuration id and the PCRE, ICU and Unicode versions seen. When two titles match and you do not know why, print both explanations side by side. Count the `reason` values of your near checks in your own metrics: a spike in `empty` is the early warning that some script is not being tokenised.

`docs/ARCHITECTURE.md` describes the modules and every design decision.

## Performance

Measured with `php bin/benchmark.php` on a shared 2-vCPU cloud VM under load (PHP 8.3, no opcache for CLI, no JIT). On this VM the same run varies by about two times with load: a quieter run gave a 34 microsecond median for the English title and 9.7 s for brute force. These are measurements on one small machine, not a promise for yours; run the script on your own hardware before you size anything.

| Operation | Result |
|---|---|
| Hash an 80-character English title | median 78 microseconds |
| Hash a 60-character Hindi title | median 117 microseconds |
| Hash a 4096-byte input | median 2.4 milliseconds |
| Distance of two fingerprints | median about 1 microsecond |
| `findNearPairs`, 10,000 items, band index, threshold 3 | 0.10 s, 14 MB |
| `findNearPairs`, 10,000 items, brute force, threshold 6 | 17 s, 1 MB |

Brute force is quadratic. For large lists at threshold 6, either use threshold 3 with the index (exact), or the index at 6 as a fast filter that can miss some pairs (see below).

## Limitations

- **Short text.** With fewer than about five tokens, one changed word moves the fingerprint a long way, and unrelated texts can land close by chance. `minTokens` stops false matches; it does not create recall. On the fixture corpus, changing one content word in a ten-word English title gives distance 9, above the default threshold.
- **Paraphrase and translation are not detected.** Two titles that say the same thing in different words, or in different languages, are far apart. Use embeddings for that.
- **Numbers.** "Share index up 312 points" and "Share index up 145 points" share most tokens. `digits: mask` makes them identical on purpose. If numbers matter, compare them separately.
- **Boilerplate** pulls unrelated titles together. See [Removing boilerplate](#removing-boilerplate).
- **Without intl,** NFC and NFKC are skipped, so fullwidth Latin or compatibility forms are not folded. The library does not fail: it sets the `normalisation_unavailable` flag and adds `-nonorm` to the configuration id, so these fingerprints never match ones from a host with intl. Choose `form: 'none'` explicitly if you accept that everywhere.
- **Unicode versions.** Character tables come from the PCRE, mbstring and ICU builds on the host. Characters newer than the oldest runtime you use may tokenise differently between hosts. The test corpus avoids them.
- **Band index above threshold 3** is a candidate filter: it never returns a wrong pair, but it can miss pairs. Above 16 it refuses. `Batch` in `auto` mode only uses the index where it is exact.
- **Single-link grouping chains:** A near B and B near C puts A and C in one group even if they are far apart. Pass `maxGroupDiameter: true` to refuse such joins.
- **Privacy.** Similar texts have similar fingerprints, and a short text can be guessed by brute force from its fingerprint. Do not treat a fingerprint as an anonymised form of private text.
- **32-bit PHP** works for hashing, hex, unsigned strings and distance, bit for bit the same as 64-bit, but more slowly. `toInt()` and `Fingerprint::fromInt()` need a 64-bit build and raise `UnsupportedPlatformException` otherwise.

## FAQ

**Why is the default threshold 6 and not scaled by length?**
Six is the value used in production for titles with three or more content words. A scaled threshold sounds safer for short titles, but the minimum-signal rule already handles them more simply and more predictably: below `minTokens` the answer is `too_short`, whatever the distance. One fixed number is easier to reason about at 2 a.m.

**Why XXH64 and not MD5?**
XXH64 is built into PHP 8.1, fast, well mixed in all 64 bits and specified with published test vectors. The input is public text, so a cryptographic hash buys nothing. If you have MD5-based fingerprints stored, plug in a `Hasher` (example above).

**Can I compare a fingerprint stored as hex?**
Yes. `Fingerprint::fromHex($hex, $configId, $tokenCount)` or `Fingerprint::fromArray($row)`. Without the configuration id the near check answers `config_mismatch`; without the token count it answers `too_short`. `distance()` works on bare hex, ints and unsigned strings.

**Which database column type?**
`BIGINT UNSIGNED` with `toUnsignedString()`, `BIGINT` with `toInt()`, or `CHAR(16)` with `hex()`. Store the configuration id and token count beside it.

**Why do Chinese and Thai titles get bigrams?**
They are written without spaces, so a whole title is one run. One token per title would make any one-character edit flip about half the bits.

**Is it safe on untrusted input?**
It validates UTF-8, caps input bytes and features, uses only fixed regex patterns, never `eval`, shell or `@`, and the test suite feeds it long runs of marks, joiners, digits and `www.` with a time budget. Stop words are matched by array lookup, never put in a regex.

**Does `setlocale()` change the output?**
No. Case folding is locale-independent and a test checks Turkish and C locales.

## Development

```sh
composer install
composer test      # PHPUnit
composer analyse   # PHPStan level 8
composer lint      # PHP_CodeSniffer, PSR-12
printf 'भारत में मानसून\n' | php bin/simhash
php bin/benchmark.php
```

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md).

## Licence

MIT. See [LICENSE](LICENSE). Test fixture titles are self-written and dedicated to the public domain under CC0.
