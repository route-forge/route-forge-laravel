<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use Illuminate\Routing\Route;
use InvalidArgumentException;
use RouteForge\Laravel\Exceptions\AliasTargetException;

/**
 * 路由别名解析器（SPEC §3.1.7）。
 *
 * 将两个声明通道合并为一张「别名 → 真实路由名」映射表：
 *   1. 路由宏 ->forgeAlias('旧名')——别名声明写在被指向（新名）路由上，
 *      扫描路由表时从 action['forge_aliases'] 读取；
 *   2. config/forge.php 的 'aliases' => ['旧名' => '新名']——集中批量声明。
 *
 * 合并规则（对齐 tier 的「显式 > 配置」优先级，SPEC §3.1.4）：
 *   - 同一别名同时经宏与 config 声明时，宏（显式）优先；
 *   - 别名与真实路由名撞车时，真实路由优先，别名被丢弃并记录警告；
 *   - 别名指向的路由名不存在（悬空）时抛 AliasTargetException（RF_BE_008）。
 *
 * 别名是元信息层概念：不参与 tier 解析 / strict_mode / unassigned 逻辑，
 * 元信息条目在目标路由所在层级注入（见 RouteRepository）。
 */
class AliasResolver
{
    /**
     * @param array<string, mixed> $configAliases config/forge.php 的 aliases 映射（键=别名，值=真实路由名）
     */
    public function __construct(private readonly array $configAliases)
    {
        foreach ($this->configAliases as $alias => $target) {
            if (!is_string($alias) || $alias === '' || !is_string($target) || $target === '') {
                throw new InvalidArgumentException(
                    'forge.aliases must be a map of [alias(string) => route name(string)]; '
                    . 'got [' . var_export($alias, true) . ' => ' . var_export($target, true) . ']',
                );
            }
        }
    }

    /**
     * 从路由表解析别名映射。
     *
     * @param iterable<Route> $routes Laravel 路由集合（原始迭代即可，不做排除过滤）
     *
     * @return array{
     *   aliases:  array<string,string>,  别名 => 真实路由名
     *   warnings: string[],              非致命问题（撞车丢弃等），由 list/管理器展示
     * }
     *
     * @throws AliasTargetException 任一别名的目标路由名在路由表中不存在
     */
    public function resolve(iterable $routes): array
    {
        $realNames = [];
        $aliases   = [];

        foreach ($routes as $route) {
            $name = $route->getName();
            if ($name === null || $name === '' || RouteRepository::isExcludedRouteName($name)) {
                continue; // forge 自身端点与框架内部路由不参与别名体系
            }
            $realNames[$name] = true;

            // 宏声明的别名存于 action（资源路由不支持别名，见 SPEC §3.1.7）
            $declared = $route->getAction()['forge_aliases'] ?? null;
            if (is_array($declared)) {
                foreach ($declared as $alias) {
                    if (is_string($alias) && $alias !== '') {
                        $aliases[$alias] = $name;
                    }
                }
            }
        }

        $warnings = [];

        // 合并 config 声明（宏优先：已存在的别名不被覆盖）
        foreach ($this->configAliases as $alias => $target) {
            if (isset($realNames[$alias])) {
                $warnings[] = "Alias [{$alias}] collides with a real route name; the real route wins and the alias is ignored.";
                continue;
            }
            if (isset($aliases[$alias])) {
                if ($aliases[$alias] !== $target) {
                    $warnings[] = "Alias [{$alias}] is declared both via ->forgeAlias() [→ {$aliases[$alias]}] and config [→ {$target}]; the explicit macro wins.";
                }
                continue;
            }
            $aliases[$alias] = $target;
        }

        // 悬空校验：目标必须是真实存在的用户路由名
        foreach ($aliases as $alias => $target) {
            if (!isset($realNames[$target])) {
                throw new AliasTargetException(
                    "Alias [{$alias}] points to route name [{$target}], which does not exist "
                    . 'in the current route table. Update or remove the alias in '
                    . 'config/forge.php or the ->forgeAlias() declaration.',
                );
            }
        }

        return ['aliases' => $aliases, 'warnings' => $warnings];
    }
}
