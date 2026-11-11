<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Exception;

use InvalidArgumentException;

/**
 * The input text or a value to parse is not acceptable: invalid UTF-8, too
 * long in throw mode, or a malformed fingerprint string.
 */
final class InvalidInputException extends InvalidArgumentException implements SimhashException
{
}
