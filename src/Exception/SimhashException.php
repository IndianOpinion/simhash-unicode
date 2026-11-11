<?php

declare(strict_types=1);

namespace IndianOpinion\Simhash\Exception;

use Throwable;

/**
 * Marker interface implemented by every exception this package throws.
 *
 * Catch this to handle any library failure in one place.
 */
interface SimhashException extends Throwable
{
}
