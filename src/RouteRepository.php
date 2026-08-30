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
     * 包自身端点路由（forge.*）与框架内部路由（如 storage.*）在所有扫描中排除；
     * routes 字段为 stdClass，空层级序列化为 {}（按路由名索引的对象契约）。
     *
     * @return array{
     *   level:string,
     *   routes:\stdClass<string,array{uri:string,methods:string[],parameters:string[],parameter_defaults:\stdClass}>
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
                // 包自身端点（forge.*）与框架内部路由（如 storage.*）不属于用户业务路由
                if (self::isExcludedRouteName($name)) {
                    continue;
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
            // (object) 强转：空层级序列化为 {} 而非 []，保持「按路由名索引的对象」契约
            'routes' => (object)$routes,
        ];

        $this->cache->set($level, $payload);
        return $payload;
    }

    /**
     * 判断路由名是否为 Route Forge 自身端点（元信息端点 / 管理器页面）。
     *
     * forge.routes.* 与 forge.manager.* 是包内部路由，不应出现在任何用户可见输出中：
     * route:forge:list、route:forge:types、管理器路由数据，以及元信息端点
     * （层级端点、摘要统计、unassigned 特殊层级）。
     *
     * 关键原因：包自身路由永远不带 tier——若参与解析，
     * strict_mode=true 时元信息端点会因它们必然抛 RF_BE_001，
     * 导致严格模式完全不可用（DESIGN.md §7 推荐生产开启严格模式）。
     * Artisan 命令与 RouteRepository 统一使用 isExcludedRouteName 过滤。
     */
    public static function isForgeRouteName(string $name): bool
    {
        return str_starts_with($name, 'forge.routes.')
            || str_starts_with($name, 'forge.manager.');
    }

    /**
     * 判断路由名是否为框架内部路由（非用户业务路由）。
     *
     * Laravel 12+ 的 FilesystemServiceProvider 会为 filesystems.disks 中
     * 启用 serve 的磁盘注册 storage.{disk} 与 storage.{disk}.upload 命名路由
     * （默认 local 磁盘即包含），它们与用户路由混在同一张路由表中。
     * 这类框架内部路由不应出现在 route:forge:list / route:forge:types、
     * 管理器数据与 unassigned 元信息端点中。
     */
    public static function isFrameworkRouteName(string $name): bool
    {
        return str_starts_with($name, 'storage.');
    }

    /**
     * 判断路由是否应从 forge 的所有用户可见输出中排除
     * （forge 自身端点 + 框架内部路由）。
     */
    public static function isExcludedRouteName(string $name): bool
    {
        return self::isForgeRouteName($name)
            || self::isFrameworkRouteName($name);
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
            // 包自身端点（forge.*）与框架内部路由（如 storage.*）不计入任何层级统计
            if (self::isExcludedRouteName($name)) {
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
            // 跳过 forge 自身端点路由与框架内部路由
            if (self::isExcludedRouteName($name)) {
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
            // 包自身端点（forge.*）与框架内部路由（如 storage.*）不属于用户业务路由，
            // 不进入 unassigned 元信息（否则前端会把包内部路由当作用户路由消费）
            if (self::isExcludedRouteName($name)) {
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
