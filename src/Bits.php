<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

use IndianOpinion\Simhash\Exception\InvalidInputException;
use IndianOpinion\Simhash\Exception\UnsupportedPlatformException;

/**
 * Bit arithmetic on 64-bit values held as 8 raw bytes, big-endian.
 *
 * Every function here works on 32-bit PHP builds too, except the integer
 * conversions, which need native 64-bit integers. Wide values are handled as
 * four 16-bit lanes so no intermediate result ever exceeds 2^31.
 *
 * @internal
 */
final class Bits
{
    public const MAX_UNSIGNED = '18446744073709551615';

    private function __construct()
    {
    }

    /**
     * Number of set bits in a native 64-bit integer (any sign).
     */
    public static function popcount64(int $x): int
    {
        return self::popcount32(($x >> 32) & 0xFFFFFFFF) + self::popcount32($x & 0xFFFFFFFF);
    }

    /**
     * Number of set bits in a value from 0 to 2^32 - 1. Needs 64-bit ints.
     */
    private static function popcount32(int $v): int
    {
        $v -= ($v >> 1) & 0x55555555;
        $v = ($v & 0x33333333) + (($v >> 2) & 0x33333333);
        $v = ($v + ($v >> 4)) & 0x0F0F0F0F;

        return (($v * 0x01010101) & 0xFFFFFFFF) >> 24;
    }

    /**
     * Number of set bits in a value from 0 to 65535. Safe on 32-bit builds.
     */
    public static function popcount16(int $v): int
    {
        $v -= ($v >> 1) & 0x5555;
        $v = ($v & 0x3333) + (($v >> 2) & 0x3333);
        $v = ($v + ($v >> 4)) & 0x0F0F;

        return ($v + ($v >> 8)) & 0x1F;
    }

    /**
     * Number of set bits in an 8-byte string, by 16-bit lanes.
     */
    public static function popcountBytes(string $bytes): int
    {
        $n = 0;
        foreach (self::lanes($bytes) as $lane) {
            $n += self::popcount16($lane);
        }

        return $n;
    }

    /**
     * Hamming distance between two 8-byte values.
     */
    public static function distance(string $a, string $b): int
    {
        $x = $a ^ $b;
        if (Platform::is64Bit()) {
            return self::popcount64(self::toInt($x));
        }

        return self::popcountBytes($x);
    }

    /**
     * Four 16-bit lanes, most significant first.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    public static function lanes(string $bytes): array
    {
        $l = unpack('n4', $bytes);
        if ($l === false || count($l) !== 4) {
            throw new InvalidInputException('A fingerprint must be exactly 8 bytes.');
        }

        return [(int) $l[1], (int) $l[2], (int) $l[3], (int) $l[4]];
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $lanes
     */
    public static function fromLanes(array $lanes): string
    {
        return pack('n4', $lanes[0], $lanes[1], $lanes[2], $lanes[3]);
    }

    public static function toInt(string $bytes): int
    {
        if (!Platform::is64Bit()) {
            throw new UnsupportedPlatformException(
                'Integer form of a fingerprint needs a 64-bit PHP build. Use hex() or toUnsignedString() instead.'
            );
        }
        $v = unpack('J', $bytes);
        if ($v === false || strlen($bytes) !== 8) {
            throw new InvalidInputException('A fingerprint must be exactly 8 bytes.');
        }

        return (int) $v[1];
    }

    public static function fromInt(int $value): string
    {
        if (!Platform::is64Bit()) {
            throw new UnsupportedPlatformException(
                'Integer form of a fingerprint needs a 64-bit PHP build. '
                . 'Use hex or the unsigned decimal string instead.'
            );
        }

        return pack('J', $value);
    }

    public static function fromHex(string $hex): string
    {
        if (strlen($hex) !== 16 || strspn($hex, '0123456789abcdefABCDEF') !== 16) {
            throw new InvalidInputException(
                'A fingerprint in hex must be exactly 16 hex digits with no prefix, got "' . self::preview($hex) . '".'
            );
        }

        return pack('H*', $hex);
    }

    public static function toHex(string $bytes): string
    {
        return bin2hex($bytes);
    }

    /**
     * Unsigned decimal string, 0 to 18446744073709551615. Works on any word size.
     */
    public static function toUnsigned(string $bytes): string
    {
        $lanes = self::lanes($bytes);
        if ($lanes === [0, 0, 0, 0]) {
            return '0';
        }
        $digits = '';
        while ($lanes !== [0, 0, 0, 0]) {
            $rem = 0;
            for ($i = 0; $i < 4; $i++) {
                $cur = $rem * 65536 + $lanes[$i];
                $lanes[$i] = intdiv($cur, 10);
                $rem = $cur % 10;
            }
            $digits .= (string) $rem;
        }

        return strrev($digits);
    }

    public static function fromUnsigned(string $decimal): string
    {
        if (preg_match('/\A(?:0|[1-9][0-9]{0,19})\z/', $decimal) !== 1) {
            throw new InvalidInputException(
                'An unsigned fingerprint must be a decimal integer with no sign, spaces or leading zeros, got "'
                . self::preview($decimal) . '".'
            );
        }
        if (strlen($decimal) === 20 && strcmp($decimal, self::MAX_UNSIGNED) > 0) {
            throw new InvalidInputException('An unsigned fingerprint cannot exceed ' . self::MAX_UNSIGNED . '.');
        }
        $lanes = [0, 0, 0, 0];
        $len = strlen($decimal);
        for ($d = 0; $d < $len; $d++) {
            $carry = ord($decimal[$d]) - 48;
            for ($i = 3; $i >= 0; $i--) {
                $v = $lanes[$i] * 10 + $carry;
                $lanes[$i] = $v & 0xFFFF;
                $carry = $v >> 16;
            }
        }

        return self::fromLanes($lanes);
    }

    public static function toBits(string $bytes): string
    {
        $out = '';
        foreach (self::lanes($bytes) as $lane) {
            $out .= str_pad(decbin($lane), 16, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    private static function preview(string $s): string
    {
        $s = substr($s, 0, 40);

        return preg_match('//u', $s) === 1 ? $s : bin2hex($s);
    }
}
