<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\InvalidInputException;

/**
 * A 64-bit simhash plus what is needed to trust it. Immutable.
 *
 * An empty fingerprint (no features after normalisation) has value zero but
 * is a sentinel, not a hash: isEmpty() is true and it never matches anything
 * in a near-duplicate check. A real hash that happens to be all zero bits is
 * not empty.
 *
 * Bit 63 is the most significant bit and the first bit of the first hex
 * digit.
 */
final class Fingerprint
{
    private function __construct(
        private readonly string $bytes,
        private readonly string $configId,
        private readonly ?int $tokenCount,
        private readonly ?int $featureCount,
        private readonly bool $empty,
        private readonly bool $truncated = false,
        private readonly bool $autoNgrams = false,
        private readonly bool $stopWordsSkipped = false,
        private readonly bool $normalisationUnavailable = false,
    ) {
    }

    /**
     * @internal Built by Simhash.
     */
    public static function create(
        string $bytes,
        string $configId,
        int $tokenCount,
        int $featureCount,
        bool $truncated,
        bool $autoNgrams,
        bool $stopWordsSkipped,
        bool $normalisationUnavailable,
    ): self {
        if (strlen($bytes) !== 8) {
            throw new InvalidInputException('A fingerprint must be exactly 8 bytes.');
        }
        $empty = $featureCount === 0;

        return new self(
            $empty ? "\0\0\0\0\0\0\0\0" : $bytes,
            $configId,
            $tokenCount,
            $featureCount,
            $empty,
            $truncated,
            $autoNgrams,
            $stopWordsSkipped,
            $normalisationUnavailable,
        );
    }

    /**
     * Parses 16 hex digits (either case, no prefix).
     *
     * Pass the stored configuration id and token count to make the result
     * usable in a near-duplicate check; without them the check reports
     * config_mismatch or too_short.
     */
    public static function fromHex(string $hex, string $configId = '', ?int $tokenCount = null): self
    {
        return self::parsed(Bits::fromHex($hex), $configId, $tokenCount);
    }

    /**
     * Accepts any PHP int with the same bit pattern (negative when bit 63 is
     * set). 64-bit builds only.
     */
    public static function fromInt(int $value, string $configId = '', ?int $tokenCount = null): self
    {
        return self::parsed(Bits::fromInt($value), $configId, $tokenCount);
    }

    /**
     * Parses the unsigned decimal form, 0 to 18446744073709551615.
     */
    public static function fromUnsignedString(string $value, string $configId = '', ?int $tokenCount = null): self
    {
        return self::parsed(Bits::fromUnsigned($value), $configId, $tokenCount);
    }

    /**
     * Rebuilds a fingerprint from toArray() output.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $hex = $data['hex'] ?? null;
        $config = $data['config'] ?? '';
        $tokens = $data['tokens'] ?? null;
        $features = $data['features'] ?? null;
        $flags = $data['flags'] ?? [];
        if (
            !is_string($hex) || !is_string($config) || !is_array($flags)
            || ($tokens !== null && !is_int($tokens)) || ($features !== null && !is_int($features))
        ) {
            throw new InvalidInputException('Stored fingerprint must have hex, config, tokens, features and flags.');
        }
        $bytes = Bits::fromHex($hex);
        $empty = $features === 0;

        return new self(
            $bytes,
            $config,
            $tokens,
            $features,
            $empty,
            in_array('truncated', $flags, true),
            in_array('auto_ngrams', $flags, true),
            in_array('stop_words_skipped', $flags, true),
            in_array('normalisation_unavailable', $flags, true),
        );
    }

    private static function parsed(string $bytes, string $configId, ?int $tokenCount): self
    {
        if ($tokenCount !== null && $tokenCount < 0) {
            throw new InvalidInputException('Token count cannot be negative.');
        }

        return new self($bytes, $configId, $tokenCount, null, false);
    }

    /**
     * Everything needed to store and later compare this fingerprint.
     *
     * @return array{hex: string, config: string, tokens: int|null, features: int|null, flags: list<string>}
     */
    public function toArray(): array
    {
        return [
            'hex' => $this->hex(),
            'config' => $this->configId,
            'tokens' => $this->tokenCount,
            'features' => $this->featureCount,
            'flags' => $this->flags(),
        ];
    }

    /**
     * 16 lowercase hex digits, zero padded.
     */
    public function hex(): string
    {
        return Bits::toHex($this->bytes);
    }

    /**
     * Signed 64-bit integer with the same bit pattern. 64-bit builds only.
     */
    public function toInt(): int
    {
        return Bits::toInt($this->bytes);
    }

    /**
     * Unsigned decimal string, for an unsigned 64-bit database column.
     */
    public function toUnsignedString(): string
    {
        return Bits::toUnsigned($this->bytes);
    }

    /**
     * 64 characters of 0 and 1, most significant bit first.
     */
    public function bits(): string
    {
        return Bits::toBits($this->bytes);
    }

    /**
     * The 8 raw bytes, big-endian.
     */
    public function bytes(): string
    {
        return $this->bytes;
    }

    public function isEmpty(): bool
    {
        return $this->empty;
    }

    /**
     * Word tokens after stop words, before shingles and n-grams. Runs in
     * scripts without spaces count as half their grapheme bigrams, rounded
     * down. Null for a parsed fingerprint stored without it.
     */
    public function tokenCount(): ?int
    {
        return $this->tokenCount;
    }

    /**
     * Features with a weight above zero that went into the hash. Null for a
     * parsed fingerprint.
     */
    public function featureCount(): ?int
    {
        return $this->featureCount;
    }

    public function configId(): string
    {
        return $this->configId;
    }

    /**
     * True when the input was cut at maxInputBytes or the feature list at
     * maxFeatures.
     */
    public function isTruncated(): bool
    {
        return $this->truncated;
    }

    public function usedAutoNgrams(): bool
    {
        return $this->autoNgrams;
    }

    public function stopWordsSkipped(): bool
    {
        return $this->stopWordsSkipped;
    }

    /**
     * True when the configuration asked for NFC, NFKC or diacritic folding
     * and intl was not loaded, so that step was skipped. The configuration
     * id carries a matching suffix, so such fingerprints never match ones
     * made with normalisation.
     */
    public function normalisationUnavailable(): bool
    {
        return $this->normalisationUnavailable;
    }

    /**
     * @return list<string>
     */
    public function flags(): array
    {
        $flags = [];
        if ($this->empty) {
            $flags[] = 'empty';
        }
        if ($this->truncated) {
            $flags[] = 'truncated';
        }
        if ($this->autoNgrams) {
            $flags[] = 'auto_ngrams';
        }
        if ($this->stopWordsSkipped) {
            $flags[] = 'stop_words_skipped';
        }
        if ($this->normalisationUnavailable) {
            $flags[] = 'normalisation_unavailable';
        }

        return $flags;
    }

    public function distanceTo(self $other): int
    {
        return Bits::distance($this->bytes, $other->bytes);
    }

    /**
     * Same bits, same configuration id and same emptiness.
     */
    public function equals(self $other): bool
    {
        return $this->bytes === $other->bytes
            && $this->configId === $other->configId
            && $this->empty === $other->empty;
    }

    public function __toString(): string
    {
        return $this->hex();
    }
}
