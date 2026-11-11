# Contributing

Thank you for helping. Bug reports, fixture titles in your language, and
small focused pull requests are all welcome.

## Setup

You need PHP 8.1 or later with mbstring and intl, and Composer.

```sh
git clone https://github.com/IndianOpinion/simhash-unicode.git
cd simhash-unicode
composer install
```

## Tests and checks

```sh
composer test      # PHPUnit 10
composer analyse   # PHPStan, level 8
composer lint      # PHP_CodeSniffer, PSR-12
composer check     # all three
```

CI runs the same on PHP 8.1, 8.2 and 8.3. Only one PHP version is needed
locally; write code that runs on 8.1 (no 8.2 or later features).

Every behaviour in `docs/PRD.md` section 4 has a test. A change in behaviour
needs a test that fails before the change and passes after it.

## Style

- `declare(strict_types=1);` in every PHP file, PSR-12, lines up to 120
  characters.
- Typed exceptions that implement `SimhashException`. Never fail silently,
  never use `@`, `eval` or shell calls.
- Prose in British spelling, short sentences, plain words. No em dashes.

## Golden fingerprints

`tests/fixtures/golden.json` pins the output for every fixture title. If your
change alters any fingerprint, that is a breaking change: bump
`Config::ALGORITHM_VERSION`, run `php tests/tools/generate-fixtures.php`,
check the diff against `explain()` output, and say so in the pull request and
in `CHANGELOG.md`.

## Fixture titles

Fixtures must be written by you, or be clearly in the public domain with the
source named. Never copy headlines from a news site. Corrections from readers
of a language are especially welcome; say which language you read.

## Pull requests

- One topic per pull request, with a clear title and a short description of
  what changes and why.
- Update `CHANGELOG.md` under Unreleased.
- Keep CI green.
- A DCO-style sign-off is not required.

By contributing you agree that your contribution is licensed under the MIT
licence of this project, and that fixture text you add is dedicated to the
public domain under CC0.
