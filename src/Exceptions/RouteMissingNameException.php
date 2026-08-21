<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Exceptions;

use RuntimeException;

/**
 * RF_BE_005：strict_mode=true 且路由设置了 tier 但没有路由名。
 *
 * @see .docs/SPEC.md §6.1
 */
class RouteMissingNameException extends RuntimeException implements ForgeExceptionContract
{
    public function code(): string
    {
        return 'RF_BE_005';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
