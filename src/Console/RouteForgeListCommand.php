<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Routing\Router;
use RouteForge\Laravel\AliasResolver;
use RouteForge\Laravel\Exceptions\ForgeExceptionContract;
use RouteForge\Laravel\RouteRepository;
use RouteForge\Laravel\TierResolver;

/**
 * 列出所有命名路由的层级分配（含别名条目，SPEC §3.1.7）
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeListCommand extends Command
{
    protected $signature = 'route:forge:list
        {--level= : 仅列出指定层级下的路由}
        {--json : 输出 JSON 数组格式}
        {--unassigned : 仅列出未分配层级的路由}
        {--aliases : 仅列出别名条目（旧名 → 真实路由名）}';

    protected $description = '列出所有命名路由的层级分配（route:forge:list --level=admin --json --unassigned）';

    public function handle(Router $router, TierResolver $resolver): int
    {
        $levels = array_keys(config('forge.levels', []));

        $filterLevel = $this->option('level');
        $onlyUnassigned = (bool) $this->option('unassigned');
        $onlyAliases = (bool) $this->option('aliases');
        $asJson = (bool) $this->option('json');

        // level 过滤校验（unassigned 特殊层级合法）
        if ($filterLevel !== null && $filterLevel !== '' && !in_array($filterLevel, array_merge($levels, ['unassigned']), true)) {
            $this->error("Unknown level: {$filterLevel}");
            $this->line('Available levels: ' . (empty($levels) ? '(none)' : implode(', ',
                    $levels)));
            return 1;
        }

        // 别名解析（SPEC §3.1.7）：悬空别名 fail-fast；撞车等非致命问题收集为 warnings
        $aliasResolver = new AliasResolver((array) config('forge.aliases', []));
        try {
            $aliasResolution = $aliasResolver->resolve($router->getRoutes());
        } catch (ForgeExceptionContract $e) {
            $this->error("[{$e->code()}] {$e->getMessage()}");
            return 1;
        }
        $aliasWarnings = $aliasResolution['warnings'];

        // 收集所有命名路由
        $rows = [];
        foreach ($router->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            // 跳过 forge 自身端点路由与框架内部路由（如 Laravel 12+ 的 storage.*）
            if (RouteRepository::isExcludedRouteName($name)) {
                continue;
            }

            $level = $resolver->resolve($route);
            $methods = array_values(array_filter(
                $route->methods(),
                fn ($m) => strtoupper($m) !== 'HEAD'
            ));

            // --level 过滤（unassigned 特殊层级可与 --level=unassigned 对齐）
            $displayLevel = $level ?? 'unassigned';
            if ($filterLevel !== null && $filterLevel !== '' && $displayLevel !== $filterLevel) {
                continue;
            }

            // --unassigned 过滤
            if ($onlyUnassigned && $level !== null) {
                continue;
            }

            $rows[] = [
                'name' => $name,
                'level' => $displayLevel,
                'methods' => $methods,
                'uri' => $route->uri(),
                'alias_of' => null,
            ];
        }

        // 别名条目：跟随目标路由的层级归属，仅当目标路由通过过滤时追加
        $rowsByName = array_column($rows, null, 'name');
        foreach ($aliasResolution['aliases'] as $alias => $target) {
            $targetRow = $rowsByName[$target] ?? null;
            if ($targetRow === null) {
                continue; // 目标被 --level/--unassigned 过滤掉，别名随目标一起隐藏
            }
            $rows[] = [
                'name' => $alias,
                'level' => $targetRow['level'],
                'methods' => $targetRow['methods'],
                'uri' => $targetRow['uri'],
                'alias_of' => $target,
            ];
        }

        // --aliases 过滤：仅显示别名条目
        if ($onlyAliases) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['alias_of'] !== null));
        }

        // 当前可用层级（始终含 unassigned 特殊层级）
        $availableLevels = array_merge($levels, ['unassigned']);

        // 过滤条件描述
        $filter = [];
        if ($filterLevel !== null && $filterLevel !== '') {
            $filter['level'] = $filterLevel;
        }
        if ($onlyUnassigned) {
            $filter['unassigned'] = true;
        }
        if ($onlyAliases) {
            $filter['aliases'] = true;
        }

        // JSON 输出（结构化对象，便于脚本消费；warnings 供 CI/脚本检测别名配置问题）
        if ($asJson) {
            $this->line(json_encode([
                'levels' => $availableLevels,
                'filter' => empty($filter) ? null : $filter,
                'count'  => count($rows),
                'warnings' => $aliasWarnings,
                'routes' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return 0;
        }

        if (empty($rows)) {
            $this->info('No routes found matching the filter.');
            return 0;
        }

        $tableRows = array_map(function (array $r) {
            return [
                $r['name'],
                $r['level'],
                implode('|', $r['methods']),
                $r['uri'],
                $r['alias_of'] ?? '—',
            ];
        }, $rows);

        $this->table(['Name', 'Level', 'Methods', 'URI', 'Alias Of'], $tableRows);

        foreach ($aliasWarnings as $warning) {
            $this->warn($warning);
        }
        return 0;
    }
}
