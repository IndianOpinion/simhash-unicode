<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Exception;

use RuntimeException;

/**
 * A PHP extension the library needs is not loaded.
 */
final class MissingExtensionException extends RuntimeException implements SimhashException
{
}
