<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Exception;

use RuntimeException;

/**
 * The operation needs a 64-bit PHP build (integer form of a fingerprint).
 */
final class UnsupportedPlatformException extends RuntimeException implements SimhashException
{
}
