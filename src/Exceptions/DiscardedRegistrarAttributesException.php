<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Exceptions;

use RuntimeException;

/**
 * RF_BE_007：strict_mode=true 且 ForgeRouteRegistrar 持有未消费属性即被销毁。
 *
 * 典型成因：`Route::group(...)->tier('x')` —— group() 返回时组已注册完毕、
 * 组属性已出栈，尾部链式属性挂在新 Registrar 上无任何消费方。
 *
 * @see .docs/SPEC.md §6.1
 */
class DiscardedRegistrarAttributesException extends RuntimeException implements ForgeExceptionContract
{
    public function code(): string
    {
        return 'RF_BE_007';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
