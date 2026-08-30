<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 元信息查询端点测试（对应 .docs/SPEC.md §3.1.5, §6.1, §7.1）。
 *
 * 覆盖：
 *   1. 200 + 响应结构（level / routes / uri / methods / parameters）
 *   2. 层级隔离：不同 level 互不污染
 *   3. 未命名路由不出现在元信息里
 *   4. 未知层级 → 404 + RF_BE_002
 *   5. strict_mode + 未声明 tier 的命名路由 → 500 + RF_BE_001
 *   6. 缓存命中：二次请求直接返回缓存，不重扫
 *
 * 说明：
 *   - ForgeServiceProvider::boot() 已注册元信息端点 GET /{endpoint_prefix}/{level}
 *     （默认 endpoint_prefix = '_forge/routes'），命名 forge.routes.show。
 *   - 默认配置下 strict_mode=false，未命中层级的路由归入 unassigned 特殊层级。
 */
class EndpointTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // 测试缓存行为需要关闭 debug 模式（debug=true 时跳过缓存）
        $app['config']->set('app.debug', false);
    }

    private function endpoint(string $level): string
    {
        return $this->summaryEndpoint() . '/' . $level;
    }

    private function summaryEndpoint(): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        // 与 ForgeServiceProvider::registerMetadataEndpoint() 保持一致的规范化
        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    public function test_endpoint_returns_200_with_expected_structure(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $response = $this->get($this->endpoint('admin'));

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('admin', $payload['level']);
        $this->assertArrayNotHasKey('cache', $payload);

        // 路由名带点（'admin.users.index'），不能用 Laravel json() 的 dot-path 访问
        // 直接从 routes 关联数组取条目
        $route = $payload['routes']['admin.users.index'] ?? null;
        $this->assertNotNull($route, 'admin.users.index should be present in routes');
        $this->assertSame('admin/users', $route['uri']);
        $this->assertSame(['GET', 'HEAD'], $route['methods']);
        $this->assertSame([], $route['parameters']);
    }

    public function test_endpoint_filters_routes_by_level(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        RouteFacade::get('/client/dashboard', static function () {})
            ->name('client.dashboard')
            ->tier('client');

        $adminRoutes = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayHasKey('admin.users.index', $adminRoutes);
        $this->assertArrayNotHasKey('client.dashboard', $adminRoutes);

        $clientRoutes = $this->get($this->endpoint('client'))->json('routes');
        $this->assertArrayHasKey('client.dashboard', $clientRoutes);
        $this->assertArrayNotHasKey('admin.users.index', $clientRoutes);
    }

    public function test_unnamed_routes_excluded_from_metadata(): void
    {
        // 命名路由
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        // 未命名路由（同层级，但不应出现在元信息里）
        RouteFacade::get('/admin/unnamed', static function () {})
            ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        $this->assertArrayHasKey('admin.users.index', $routes);
        $this->assertCount(1, $routes);
    }

    public function test_unknown_level_returns_404(): void
    {
        $response = $this->get($this->endpoint('nonexistent'));

        $response->assertStatus(404);
        $this->assertSame('RF_BE_002', $response->json('error.code'));
        $this->assertSame('nonexistent', $response->json('error.level'));
    }

    public function test_strict_mode_unassigned_route_returns_500(): void
    {
        config()->set('forge.strict_mode', true);

        // 用户注册的未声明 tier 的命名路由；不匹配任何 prefix/middleware
        RouteFacade::get('/orphan', static function () {})
            ->name('orphan');

        // strict_mode=true 时，扫描任一层级都会遍历所有命名路由，
        // 命中未分配层级的用户路由（orphan）时 TierResolver 抛 RF_BE_001。
        // 包自身端点路由（forge.routes.*）已在扫描中排除，不参与解析。
        $response = $this->get($this->endpoint('admin'));

        $response->assertStatus(500);
        $this->assertSame('RF_BE_001', $response->json('error.code'));
    }

    public function test_strict_mode_all_routes_assigned_returns_200(): void
    {
        // 回归：strict_mode=true 且所有用户路由均已分配层级时，端点必须正常工作。
        // 修复前包自身路由（forge.routes.*）参与解析，导致必然抛 RF_BE_001、
        // 严格模式完全不可用（与 DESIGN.md §7「生产建议开启严格模式」矛盾）。
        config()->set('forge.strict_mode', true);

        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $summary = $this->get($this->summaryEndpoint());
        $summary->assertStatus(200);
        $this->assertSame(1, $summary->json('levels.admin.route_count'));

        $level = $this->get($this->endpoint('admin'));
        $level->assertStatus(200);
        $this->assertArrayHasKey('admin.users.index', $level->json('routes'));
    }

    public function test_cache_hit_avoids_rescan(): void
    {
        config()->set('forge.cache_driver', 'array');
    
        // 使用 public 层级验证缓存命中行为（cache_ttl 默认 3600）
        RouteFacade::get('/public/info', static function () {})
            ->name('public.info')
            ->tier('public');
    
        // 首次请求：cache miss → 扫描 → 写入 array 缓存
        $firstRoutes = $this->get($this->endpoint('public'))->json('routes');
        $this->assertArrayHasKey('public.info', $firstRoutes);
    
        // 在缓存写之后，再注册一条 public 层级路由
        RouteFacade::get('/public/help', static function () {})
            ->name('public.help')
            ->tier('public');
    
        // 二次请求：cache hit → 直接返回缓存，不重扫
        $secondRoutes = $this->get($this->endpoint('public'))->json('routes');
    
        $this->assertSame($firstRoutes, $secondRoutes);
        $this->assertArrayNotHasKey('public.help', $secondRoutes);
    }

    public function test_summary_cache_hit_avoids_rescan(): void
    {
        config()->set('forge.cache_driver', 'array');

        RouteFacade::get('/admin/one', static function () {})
            ->name('admin.one')
            ->tier('admin');

        // 首次摘要：扫描并缓存 admin.route_count
        $firstCount = $this->get($this->summaryEndpoint())->json('levels.admin.route_count');
        $this->assertSame(1, $firstCount);

        // 缓存写入后再注册一条 admin 路由
        RouteFacade::get('/admin/two', static function () {})
            ->name('admin.two')
            ->tier('admin');

        // 二次摘要：命中缓存，route_count 仍是旧值（未重扫）
        $secondCount = $this->get($this->summaryEndpoint())->json('levels.admin.route_count');
        $this->assertSame(1, $secondCount);
    }

    public function test_endpoint_serializes_empty_parameter_defaults_as_object(): void
    {
        // parameter_defaults 经 (object) 强转，空默认值应序列化为 JSON 对象 {} 而非数组 []
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $content = $this->get($this->endpoint('admin'))->getContent();
        $this->assertStringContainsString('"parameter_defaults":{}', $content);
    }

    public function test_summary_endpoint_returns_scheme_version_levels_config(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        // orphan 路由：不匹配任何层级 prefix/middleware，且无显式 tier → 归入 unassigned 特殊层级
        RouteFacade::get('/orphan', static function () {})
            ->name('orphan');

        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'schemeVersion',
            'levels' => ['public', 'client', 'manage', 'admin', 'unassigned'],
            'config' => ['strict_mode', 'endpoint_prefix', 'url_prefix', 'cache_ttl'],
        ]);

        $payload = $response->json();

        // schemeVersion：默认 1，用于后续迭代的格式兼容判断（SPEC §3.1.6）
        $this->assertSame(1, $payload['schemeVersion']);

        // 摘要不再内联返回 unassigned 路由列表，明细经 unassigned 层级端点另行获取
        $this->assertArrayNotHasKey('unassigned', $payload);

        // levels 中每个层级（含 unassigned 特殊层级）应包含 description/load/route_count/route
        foreach ($payload['levels'] as $levelName => $levelInfo) {
            $this->assertArrayHasKey('description', $levelInfo);
            $this->assertArrayHasKey('load', $levelInfo);
            $this->assertArrayHasKey('route_count', $levelInfo);
            $this->assertArrayNotHasKey('cache', $levelInfo);

            // route 字段：该层级元信息端点的请求信息（SPEC §3.1.6）
            $this->assertArrayHasKey('route', $levelInfo);
            $this->assertArrayHasKey('uri', $levelInfo['route']);
            $this->assertArrayHasKey('methods', $levelInfo['route']);
            $this->assertSame("/_forge/routes/{$levelName}", $levelInfo['route']['uri']);
            $this->assertSame(['GET', 'HEAD'], $levelInfo['route']['methods']);
        }

        // admin 层级 route_count 应为 1（仅 admin.users.index）
        $this->assertSame(1, $payload['levels']['admin']['route_count']);

        // unassigned 特殊层级摘要：结构与已定义层级一致；
        // 包自身路由已排除，route_count 精确等于 orphan 数量
        $unassigned = $payload['levels']['unassigned'];
        $this->assertSame('lazy', $unassigned['load']);
        $this->assertSame(1, $unassigned['route_count']);

        // config 摘要
        $this->assertFalse($payload['config']['strict_mode']);
        $this->assertSame('/_forge/routes', $payload['config']['endpoint_prefix']);
        $this->assertNull($payload['config']['url_prefix']);
        $this->assertSame(3600, $payload['config']['cache_ttl']);
    }

    public function test_summary_endpoint_scheme_version_follows_config(): void
    {
        config()->set('forge.scheme_version', 2);

        $payload = $this->get($this->summaryEndpoint())->json();

        $this->assertSame(2, $payload['schemeVersion']);
    }

    public function test_unassigned_level_endpoint_returns_unassigned_routes(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        RouteFacade::get('/orphan', static function () {})
            ->name('orphan');

        $response = $this->get($this->endpoint('unassigned'));

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('unassigned', $payload['level']);

        // orphan 归入 unassigned；已分配层级的路由不出现（避免路由泄露到错误层级）
        $this->assertArrayHasKey('orphan', $payload['routes']);
        $this->assertArrayNotHasKey('admin.users.index', $payload['routes']);

        // 包自身端点路由（修复前会泄露进 unassigned，被前端当作用户路由消费）
        $this->assertArrayNotHasKey('forge.routes.index', $payload['routes']);
        $this->assertArrayNotHasKey('forge.routes.show', $payload['routes']);

        // 条目结构与已定义层级端点一致（按路由名索引，无冗余 name 字段）
        $route = $payload['routes']['orphan'];
        $this->assertSame('orphan', $route['uri']);
        $this->assertSame(['GET', 'HEAD'], $route['methods']);
        $this->assertSame([], $route['parameters']);
        $this->assertArrayHasKey('parameter_defaults', $route);
    }

    public function test_unassigned_level_reflects_routes_across_requests(): void
    {
        // fallback_level 已移除：unassigned 是唯一的兜底机制，
        // 未命中层级的命名路由始终可在 unassigned 特殊层级查到。
        RouteFacade::get('/orphan-a', static function () {})
            ->name('orphan.a');
        RouteFacade::get('/orphan-b', static function () {})
            ->name('orphan.b');

        $summary = $this->get($this->summaryEndpoint())->json();
        // 包自身路由已排除，精确等于两条 orphan 路由
        $this->assertSame(2, $summary['levels']['unassigned']['route_count']);

        $routes = $this->get($this->endpoint('unassigned'))->json('routes');
        $this->assertArrayHasKey('orphan.a', $routes);
        $this->assertArrayHasKey('orphan.b', $routes);
    }

    public function test_summary_endpoint_url_prefix_null_by_default(): void
    {
        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertArrayHasKey('url_prefix', $payload['config']);
        $this->assertNull($payload['config']['url_prefix']);
    }

    public function test_summary_endpoint_url_prefix_with_full_url(): void
    {
        config()->set('forge.url_prefix', 'https://api.example.com/v1');

        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('https://api.example.com/v1', $payload['config']['url_prefix']);
    }

    public function test_summary_endpoint_url_prefix_with_path_only(): void
    {
        config()->set('forge.url_prefix', '/api/v1');

        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertSame('/api/v1', $payload['config']['url_prefix']);
    }

    public function test_summary_endpoint_url_prefix_empty_string_returns_null(): void
    {
        config()->set('forge.url_prefix', '');

        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(200);
        $payload = $response->json();
        $this->assertNull($payload['config']['url_prefix']);
    }

    public function test_endpoint_returns_parameter_defaults_empty_when_no_defaults(): void
    {
        RouteFacade::get('/admin/users/{id}', static function () {})
                   ->name('admin.users.show')
                   ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');
        $route  = $routes['admin.users.show'];

        $this->assertArrayHasKey('parameter_defaults', $route);
        // 无默认值时，parameter_defaults 为空对象（JSON 解码后为空数组）
        $this->assertEmpty($route['parameter_defaults']);
    }

    public function test_endpoint_returns_parameter_defaults_with_values(): void
    {
        RouteFacade::get('/admin/posts/{page?}', static function () {})
                   ->name('admin.posts.index')
                   ->tier('admin')
                   ->defaults('page', '1');

        $routes = $this->get($this->endpoint('admin'))->json('routes');
        $route  = $routes['admin.posts.index'];

        $this->assertArrayHasKey('parameter_defaults', $route);
        $this->assertSame(['page' => '1'], $route['parameter_defaults']);
    }

    public function test_unassigned_level_endpoint_includes_parameter_defaults(): void
    {
        RouteFacade::get('/items/{page?}', static function () {})
                   ->name('items.index')
                   ->defaults('page', '1');

        $routes = $this->get($this->endpoint('unassigned'))->json('routes');

        $this->assertArrayHasKey('items.index', $routes);
        $this->assertArrayHasKey('parameter_defaults', $routes['items.index']);
        $this->assertSame(['page' => '1'], $routes['items.index']['parameter_defaults']);
    }

    public function test_empty_level_serializes_routes_as_object(): void
    {
        // admin 层级下无任何路由时，routes 必须序列化为对象 {} 而非数组 []，
        // 保持「按路由名索引的对象」契约（修复前空层级返回 []，
        // 与 parameter_defaults 空值序列化为 {} 的既有约定不一致）。
        $content = (string) $this->get($this->endpoint('admin'))->getContent();

        $this->assertStringContainsString('"routes":{}', $content);
        $this->assertStringNotContainsString('"routes":[]', $content);
    }
}
