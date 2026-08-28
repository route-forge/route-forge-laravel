<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use RouteForge\Laravel\Cache\RouteCache;

/**
 * 路由仓库：扫描 Laravel RouteCollection，按层级分组返回元信息。
 *
 * 元信息结构（对应前端 RouteMeta）：
 *   {
 *     "level": "admin",
 *     "routes": {
 *       "admin.users.show": { "uri": "...", "methods": [...], "parameters": [...], "parameter_defaults": {...} }
 *     }
 *   }
 */
readonly class RouteRepository
{
    /**
     * 特殊层级名：未命中任何层级的命名路由归属此层级，
     * 通过与已定义层级相同的端点格式获取：GET /{endpoint_prefix}/unassigned
     */
    public const UNASSIGNED_LEVEL = 'unassigned';

    /**
     * 摘要端点响应格式版本（schemeVersion 字段）。
     * 后续迭代引入不兼容的格式变更时递增，前端据此做版本兼容。
     */
    public const SCHEME_VERSION = 1;

    public function __construct(
        private Router       $router,
        private TierResolver $tierResolver,
        private RouteCache   $cache,
        private array        $levelsConfig,
    ) {}

    /**
     * 取某层级下所有命名路由的元信息（带缓存）。
     *
     * level 支持特殊值 unassigned：返回所有未命中层级的命名路由，
     * 响应结构与已定义层级完全一致。
     *
     * @return array{
     *   level:string,
     *   routes:array<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>}>
     * }
     */
    public function getRoutesByLevel(string $level): array
    {
        $isUnassigned = $level === self::UNASSIGNED_LEVEL;
        if (!$isUnassigned && !isset($this->levelsConfig[$level])) {
            throw new Exceptions\UnknownLevelException("Unknown level: $level");
        }

        $cached = $this->cache->get($level);
        if ($cached !== null) {
            return $cached;
        }

        if ($isUnassigned) {
            // unassigned 特殊层级：数据源为未命中任何层级的命名路由（按路由名索引）
            $routes = $this->getUnassignedRoutes();
        } else {
            $routes = [];
            foreach ($this->router->getRoutes() as $route) {
                /** @var Route $route */
                $name = $route->getName();
                if ($name === null) {
                    continue; // 未命名路由不出现在元信息里
                }
                $resolved = $this->tierResolver->resolve($route);
                if ($resolved !== $level) {
                    continue;
                }
                $routes[$name] = [
                    'uri'                => $route->uri(),
                    'methods'            => $route->methods(),
                    'parameters'         => $route->parameterNames(),
                    'parameter_defaults' => (object)$route->defaults,
                ];
            }
        }

        $payload = [
            'level'  => $level,
            'routes' => $routes,
        ];

        $this->cache->set($level, $payload);
        return $payload;
    }

    /**
     * 返回所有层级名（仅用于调试 / 健康检查）。
     */
    public function allLevels(): array
    {
        return array_keys($this->levelsConfig);
    }

    /**
     * 失效指定层级缓存；不传参失效全部。
     *
     * 摘要端点缓存（route-forge:summary）也需一并失效，
     * 因为 levels 概览的 route_count（含 unassigned 特殊层级）依赖路由表。
     */
    public function invalidate(?string $level = null): void
    {
        if ($level !== null) {
            $this->cache->forget($level);
        } else {
            $this->cache->clear();
        }
        // 摘要端点缓存也需失效
        $this->cache->forget('summary');
    }

    /**
     * 摘要端点响应（SPEC §3.1.6）：返回格式版本（schemeVersion）、
     * 所有层级概览（含 unassigned 特殊层级）与全局配置。
     *
     * unassigned 路由明细不在摘要中内联返回，前端按摘要中 unassigned 层级
     * 的 route 字段另行请求 GET /{endpoint_prefix}/unassigned 获取。
     *
     * 缓存策略：TTL 由 RouteCache 构造函数统一控制（config('forge.cache_ttl')）。
     * 缓存 key：route-forge:summary
     *
     * @return array{
     *   schemeVersion: int,
     *   levels: array<string,array{description:string,load:string,route_count:int,route:array{uri:string,methods:string[]}}>,
     *   config: array{strict_mode:bool,endpoint_prefix:string,url_prefix:string|null,cache_ttl:int|null}
     * }
     */
    public function getSummary(): array
    {
        $cached = $this->cache->get('summary');
        if ($cached !== null) {
            return $cached;
        }

        // levels 概览：每个层级 description/load + route_count + route（端点自描述）
        $levelsSummary    = [];
        $levelRouteCounts = $this->countRoutesPerLevel();
        $endpointPrefix   = '/' . ltrim(rtrim((string) config('forge.endpoint_prefix', '/_forge/routes'), '/'), '/');

        foreach ($this->levelsConfig as $level => $cfg) {
            $levelsSummary[$level] = [
                'description' => $cfg['description'] ?? '',
                'load'        => $cfg['load'] ?? 'lazy',
                'route_count' => $levelRouteCounts['counts'][$level] ?? 0,
                'route'       => [
                    'uri'     => "{$endpointPrefix}/{$level}",
                    'methods' => ['GET', 'HEAD'],
                ],
            ];
        }

        // unassigned 特殊层级：与已定义层级结构一致
        $levelsSummary[self::UNASSIGNED_LEVEL] = [
            'description' => '未命中任何层级的路由',
            'load'        => 'lazy',
            'route_count' => $levelRouteCounts['unassigned'],
            'route'       => [
                'uri'     => "{$endpointPrefix}/" . self::UNASSIGNED_LEVEL,
                'methods' => ['GET', 'HEAD'],
            ],
        ];

        // 全局配置摘要
        $urlPrefix = config('forge.url_prefix');
        $config = [
            'strict_mode'     => config('forge.strict_mode', false),
            'endpoint_prefix' => (string)config('forge.endpoint_prefix', '/_forge/routes'),
            'url_prefix' => is_string($urlPrefix) && $urlPrefix !== '' ? $urlPrefix : null,
            'cache_ttl'       => config('forge.cache_ttl'),
        ];

        $payload = [
            'schemeVersion' => (int) config('forge.scheme_version', self::SCHEME_VERSION),
            'levels'        => $levelsSummary,
            'config'        => $config,
        ];

        $this->cache->set('summary', $payload);

        return $payload;
    }

    /**
     * 扫描所有命名路由，返回每个层级命中的路由数量及未分配数量。
     *
     * 一次遍历同时统计各层级命中数和 unassigned 数，
     * 避免 getSummary() 再调用 getUnassignedRoutes() 造成二次全量扫描。
     *
     * @return array{counts: array<string,int>, unassigned: int}
     */
    private function countRoutesPerLevel(): array
    {
        $counts = [];
        $unassigned = 0;
        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            $resolved = $this->tierResolver->resolve($route);
            if ($resolved !== null) {
                $counts[$resolved] = ($counts[$resolved] ?? 0) + 1;
            } else {
                $unassigned++;
            }
        }
        return ['counts' => $counts, 'unassigned' => $unassigned];
    }

    /**
     * 获取所有命名路由及其层级分配结果（管理器页面专用，不缓存）。
     *
     * 一次遍历收集所有路由的元信息与层级归属，供管理器页面按层级分组展示、
     * 搜索过滤与详情查看。
     *
     * @return array{routes: list<array{name:string,uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>,middleware:string[],tier:string}>,
     *                       tiers: array<string,int>}
     */
    public function getAllRoutesWithTiers(): array
    {
        $routes = [];
        $tiers  = [];

        foreach ($this->router->getRoutes() as $route) {
            /** @var Route $route */
            $name = $route->getName();
            if ($name === null || $name === '') {
                continue;
            }
            // 跳过 forge 自身端点路由
            if (str_starts_with($name, 'forge.routes.') || str_starts_with($name,
                    'forge.manager')) {
                continue;
            }

            $resolved     = $this->tierResolver->resolve($route);
            $tier         = $resolved ?? self::UNASSIGNED_LEVEL;
            $tiers[$tier] = ($tiers[$tier] ?? 0) + 1;

            $routes[] = [
                'name'               => $name,
                'uri'                => $route->uri(),
                'methods'            => array_values(array_filter(
                    $route->methods(),
                    fn(string $m) => strtoupper($m) !== 'HEAD',
                )),
                'parameters'         => $route->parameterNames(),
                'parameter_defaults' => (array)$route->defaults,
                'middleware'         => $route->gatherMiddleware(),
                'tier'               => $tier,
            ];
        }

        return ['routes' => $routes, 'tiers' => $tiers];
    }

    /**
     * unassigned 特殊层级的路由元信息（按路由名索引，与层级端点 routes 结构一致）。
     *
     * tierResolver->resolve() 返回 null 的命名路由即为"未分配"，
     * strict_mode=false 时未命中任何层级的路由统一归入此特殊层级。
     *
     * @return array<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:array<string,mixed>}>
     */
    public function getUnassignedRoutes(): array
    {
        $unassigned = [];
        foreach ($this->router->getRoutes() as $route) {
            $name = $route->getName();
            if ($name === null) {
                continue;
            }
            if ($this->tierResolver->resolve($route) === null) {
                $unassigned[$name] = [
                    'uri'                => $route->uri(),
                    'methods'            => $route->methods(),
                    'parameters'         => $route->parameterNames(),
                    'parameter_defaults' => (object)$route->defaults,
                ];
            }
        }

        return $unassigned;
    }
}
