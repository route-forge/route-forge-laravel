<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Exceptions;

use RuntimeException;

/**
 * RF_BE_008：别名映射指向的路由名不存在（悬空别名）。
 *
 * 典型场景：config/forge.php 的 aliases 键值对中，值（真实路由名）
 * 在当前路由表中不存在——通常是路由被删除/再改名后忘记同步清理别名。
 * fail-fast 抛出，避免前端拿着一个永远 404 的"可用路由名"。
 *
 * @see .docs/SPEC.md §6.1, §3.1.7
 */
class AliasTargetException extends RuntimeException implements ForgeExceptionContract
{
    public function code(): string
    {
        return 'RF_BE_008';
    }

    public function httpStatus(): int
    {
        return 500;
    }
}
