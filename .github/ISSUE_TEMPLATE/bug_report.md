---
name: Bug report
about: Something hashes, tokenises or matches wrongly
labels: bug
---

## What happened

<!-- One or two sentences. -->

## Input

<!-- The text(s), or a minimal script. Please do not paste private text. -->

```php
$simhash = new IndianOpinion\Simhash\Simhash();
print_r($simhash->explain('...'));
```

## Expected

## Actual

<!-- Paste the explain() output if you can. -->

## Environment

- Library version:
- PHP version:
- intl loaded and ICU version (`php -r 'echo INTL_ICU_VERSION;'`):
- PCRE version (`php -r 'echo PCRE_VERSION;'`):
- 32-bit or 64-bit:
