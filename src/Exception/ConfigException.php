<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Exception;

use InvalidArgumentException;

/**
 * A configuration value is out of range, or a custom hasher or weight returned something unusable.
 */
final class ConfigException extends InvalidArgumentException implements SimhashException
{
}
