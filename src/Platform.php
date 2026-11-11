<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash;

/**
 * Runtime capability checks, with a test seam.
 *
 * The seam lets the test suite exercise the 32-bit code path and the
 * "intl missing" path on an ordinary 64-bit build with intl loaded. Library
 * users never need to call simulate().
 *
 * @internal
 */
final class Platform
{
    private static ?bool $intl = null;
    private static ?bool $mbstring = null;
    private static ?bool $is64 = null;

    private function __construct()
    {
    }

    public static function hasIntl(): bool
    {
        return self::$intl ?? (class_exists(\Normalizer::class) && function_exists('grapheme_extract'));
    }

    public static function hasMbstring(): bool
    {
        return self::$mbstring ?? function_exists('mb_convert_case');
    }

    public static function is64Bit(): bool
    {
        return self::$is64 ?? (PHP_INT_SIZE === 8);
    }

    /**
     * Pretend a capability is missing. Pass null to restore detection.
     * A simulated capability can only be taken away, never added.
     */
    public static function simulate(?bool $intl = null, ?bool $mbstring = null, ?bool $is64 = null): void
    {
        self::$intl = $intl === true ? null : $intl;
        self::$mbstring = $mbstring === true ? null : $mbstring;
        self::$is64 = $is64 === true ? null : $is64;
    }

    public static function reset(): void
    {
        self::$intl = null;
        self::$mbstring = null;
        self::$is64 = null;
    }
}
