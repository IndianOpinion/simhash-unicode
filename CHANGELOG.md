# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project
uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Any change
that alters fingerprints for the same text and settings bumps
`Config::ALGORITHM_VERSION` and is listed as breaking.

## [Unreleased]

## [0.1.0] - 2026-11-11

### Added

- 64-bit simhash of short text in any script, with Unicode-aware tokens:
  letters, digits and combining marks, so Indic vowel signs, virama and nukta
  stay inside the word.
- Script boundaries split tokens; Han, Hiragana, Katakana, Thai, Lao, Khmer
  and Myanmar runs become grapheme bigrams.
- Conjunct-aware graphemes (ICU when intl is loaded, a built-in rule
  otherwise, the same output from both).
- Normalisation: NFKC, NFC or none; full, simple or no case folding; optional
  Latin diacritic folding and Arabic harakat stripping; Malayalam chillu
  unification; invisible characters, bidi controls and tatweel removed.
- Digits in any script folded to ASCII, kept, dropped or masked. URLs and
  e-mail addresses reduced to their host, dropped or kept. Emoji dropped or
  kept as whole sequences.
- Caller-supplied stop-word lists (none shipped), with a fallback that never
  empties a title.
- Word shingles, explicit character n-grams, and automatic trigrams for texts
  with fewer than three tokens.
- Weights by kind, by token map or by callback, validated.
- XXH64 token hash behind a `Hasher` interface.
- `Fingerprint` with hex, signed int, unsigned decimal string, bits,
  `toArray()` and parsers, flags, token and feature counts, and a
  configuration id.
- Empty input gives an empty sentinel that never matches.
- `isNearDuplicate()` with reasons `near`, `far`, `empty`, `too_short` and
  `config_mismatch`.
- `Batch::findNearPairs()` (brute force or band index) and
  `Batch::groupNearDuplicates()` (single or complete link), and a streaming
  `BandIndex`.
- `explain()` report for debugging.
- 32-bit PHP support for everything except the integer form.
- `bootstrap.php` for use without Composer; `bin/simhash` and
  `bin/benchmark.php` development tools; two examples.
- Self-written fixture corpus in 16 script groups with golden fingerprints.

[Unreleased]: https://github.com/IndianOpinion/simhash-unicode/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/IndianOpinion/simhash-unicode/releases/tag/v0.1.0
