<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Console;

use Illuminate\Console\Command;
use RouteForge\Laravel\Cache\RouteCache;
use RouteForge\Laravel\RouteRepository;

/**
 * 清除 Route Forge 路由元信息缓存
 * @see .docs/SPEC.md §3.2
 */
class RouteForgeClearCommand extends Command
{
    protected $signature = 'route:forge:clear
        {--level= : 仅清除指定层级的缓存；不传则清除全部（含摘要端点）}';

    protected $description = '清除 Route Forge 路由元信息缓存';

    public function handle(RouteCache $cache): int
    {
        $level = $this->option('level');

        if ($level !== null && $level !== '') {
            // 已定义层级 + unassigned 特殊层级（其端点缓存独立存储）
            $levels   = array_keys(config('forge.levels', []));
            $levels[] = RouteRepository::UNASSIGNED_LEVEL;
            if (!in_array($level, $levels, true)) {
                $this->error("Unknown level: {$level}");
                $this->line('Available levels: ' . (empty($levels) ? '(none)' : implode(', ', $levels)));
                return 1;
            }

            $cache->forget($level);
            // 摘要端点的 route_count 依赖路由数据，清除指定层级后需同步失效摘要缓存
            $cache->forget('summary');
            $this->info("Route Forge cache cleared for level: {$level}");
        } else {
            $cache->clear();
            $this->info('Route Forge cache cleared successfully.');
        }

        return 0;
    }
}
