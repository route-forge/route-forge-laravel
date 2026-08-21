<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Exceptions;

use RuntimeException;

/**
 * RF_BE_006：classifier 回调返回的层级名不在 levels 配置中。
 *
 * @see .docs/SPEC.md §6.1
 */
class UnknownClassifierTierException extends RuntimeException implements ForgeExceptionContract
{
    public function code(): string
    {
        return 'RF_BE_006';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
