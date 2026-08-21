<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Unit;

use Illuminate\Routing\Route;
use PHPUnit\Framework\TestCase;
use RouteForge\Laravel\Exceptions\RouteMissingNameException;
use RouteForge\Laravel\Exceptions\UnknownClassifierTierException;
use RouteForge\Laravel\TierResolver;

/**
 * TierResolver 单元测试（对应 .docs/SPEC.md §3.1.2 中间件匹配模式与多层级 last-wins）。
 *
 * 直接 mock Illuminate\Routing\Route，不走 Laravel 容器，聚焦匹配逻辑。
 */
class TierResolverTest extends TestCase
{
    /**
     * 构造一个配置好 uri / gatherMiddleware / getAction / getName 的 Route 桩。
     * 使用 createStub（非验证型），避免 PHPUnit 13「mock 无 expectations」提示。
     */
    private function makeRoute(string $uri, array $middlewares, array $action = []): Route
    {
        $route = $this->createStub(Route::class);
        $route->method('uri')->willReturn($uri);
        $route->method('gatherMiddleware')->willReturn($middlewares);
        $route->method('getAction')->willReturn($action);
        $route->method('getName')->willReturn('test.route');
        return $route;
    }

    /**
     * 构造一个未命名路由桩（getName 返回 null）。
     */
    private function makeUnnamedRoute(string $uri, array $middlewares, array $action = []): Route
    {
        $route = $this->createStub(Route::class);
        $route->method('uri')->willReturn($uri);
        $route->method('gatherMiddleware')->willReturn($middlewares);
        $route->method('getAction')->willReturn($action);
        $route->method('getName')->willReturn(null);
        return $route;
    }

    private function makeResolver(array $levels): TierResolver
    {
        return new TierResolver($levels);
    }

    // ---------------------------------------------------------------------
    // any 模式（OR，默认）
    // ---------------------------------------------------------------------

    public function test_any_mode_matches_when_any_middleware_present(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => 'any']],
        ]);

        $route = $this->makeRoute('/x', ['auth']);
        $this->assertSame('admin', $resolver->resolve($route));
    }

    public function test_any_mode_does_not_match_when_no_middleware_present(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => 'any']],
        ]);

        // 无 prefix、无任何匹配中间件 → 不命中；无 fallback 且非 strict → null
        $route = $this->makeRoute('/x', []);
        $this->assertNull($resolver->resolve($route));
    }

    public function test_default_middleware_match_is_any_when_omitted(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin']]],
        ]);

        $route = $this->makeRoute('/x', ['admin']);
        $this->assertSame('admin', $resolver->resolve($route));
    }

    // ---------------------------------------------------------------------
    // all 模式（AND）
    // ---------------------------------------------------------------------

    public function test_all_mode_matches_when_all_middlewares_present(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => 'all']],
        ]);

        // 多带一个无关中间件也应命中
        $route = $this->makeRoute('/x', ['auth', 'admin', 'web']);
        $this->assertSame('admin', $resolver->resolve($route));
    }

    public function test_all_mode_does_not_match_when_missing_one_middleware(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => 'all']],
        ]);

        $route = $this->makeRoute('/x', ['auth']);
        $this->assertNull($resolver->resolve($route));
    }

    // ---------------------------------------------------------------------
    // DNF 嵌套数组模式
    // ---------------------------------------------------------------------

    public function test_dnf_single_conjunction_is_and(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => [[0, 1]]]],
        ]);

        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['auth', 'admin'])));
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['auth'])));
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['admin'])));
    }

    public function test_dnf_multiple_disjunctions_is_or(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['middleware' => ['auth', 'admin'], 'middleware_match' => [[0], [1]]]],
        ]);

        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['auth'])));
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['admin'])));
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['web'])));
    }

    public function test_dnf_complex_conjunction_and_disjunction(): void
    {
        // (mw[0] AND mw[1]) OR mw[2] === (auth AND admin) OR super_admin
        $resolver = $this->makeResolver([
            'admin' => ['match' => [
                'middleware' => ['auth', 'admin', 'super_admin'],
                'middleware_match' => [[0, 1], [2]],
            ]],
        ]);

        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['auth', 'admin'])));          // 第一组 AND 命中
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['super_admin'])));           // 第二组单命中
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('/x', ['auth', 'super_admin'])));  // 第二组命中
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['auth'])));                           // 仅 auth 不命中
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['admin'])));                          // 仅 admin 不命中
        $this->assertNull($resolver->resolve($this->makeRoute('/x', ['web'])));                            // 无关不命中
    }

    // ---------------------------------------------------------------------
    // last-wins：多层级同时命中取最后一个
    // ---------------------------------------------------------------------

    public function test_last_wins_returns_last_matching_level_in_array_order(): void
    {
        // public 与 admin 都能命中同一路由（都按 any 匹配各自中间件）
        $resolver = $this->makeResolver([
            'public' => ['match' => ['middleware' => ['web']]],
            'admin'  => ['match' => ['middleware' => ['auth']]],
        ]);

        // 路由同时具备 web 与 auth → 两个层级都命中，取最后一个 = admin
        $route = $this->makeRoute('/x', ['auth', 'web']);
        $this->assertSame('admin', $resolver->resolve($route));
    }

    public function test_last_wins_order_is_respected(): void
    {
        // 反转定义顺序后，last-wins 应指向 public（后定义的）
        $resolver = $this->makeResolver([
            'admin'  => ['match' => ['middleware' => ['auth']]],
            'public' => ['match' => ['middleware' => ['web']]],
        ]);

        $route = $this->makeRoute('/x', ['auth', 'web']);
        $this->assertSame('public', $resolver->resolve($route));
    }

    // ---------------------------------------------------------------------
    // prefix 与空配置
    // ---------------------------------------------------------------------

    public function test_prefix_match(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => ['prefix' => ['admin']]],
        ]);

        // 注意：Laravel Route::uri() 规范化后无前导斜杠（与 EndpointTest 中 'admin/users' 一致）
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('admin/users', [])));
        // 完全相等的 URI 也应命中
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('admin', [])));
        $this->assertNull($resolver->resolve($this->makeRoute('public/users', [])));
    }

    public function test_prefix_or_middleware_both_can_trigger_hit(): void
    {
        $resolver = $this->makeResolver([
            'admin' => ['match' => [
                'prefix' => ['admin'],
                'middleware' => ['auth'],
                'middleware_match' => 'all',
            ]],
        ]);

        // prefix 命中即可（OR 关系），即便 middleware 全不满足
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('admin/users', [])));
        // middleware 命中即可，即便 prefix 不匹配
        $this->assertSame('admin', $resolver->resolve($this->makeRoute('public/users', ['auth'])));
        // 两者都不命中
        $this->assertNull($resolver->resolve($this->makeRoute('public/users', [])));
    }

    public function test_empty_match_config_does_not_match_anything(): void
    {
        $resolver = $this->makeResolver([
            'bogus' => ['match' => []],
        ]);

        // 空 match 不应全量命中；非 strict、无 fallback → null
        $this->assertNull($resolver->resolve($this->makeRoute('/anything', ['any.mw'])));
    }

    // ---------------------------------------------------------------------
    // 有 tier 无 name 的前置检查
    // ---------------------------------------------------------------------

    public function test_strict_mode_throws_when_tier_set_but_no_name(): void
    {
        $resolver = new TierResolver([], null, strictMode: true);

        $route = $this->makeUnnamedRoute('/admin/users', [], ['tier' => 'admin']);

        $this->expectException(RouteMissingNameException::class);
        $resolver->resolve($route);
    }

    public function test_non_strict_mode_returns_null_when_tier_set_but_no_name(): void
    {
        $resolver = new TierResolver([], null, strictMode: false);

        $route = $this->makeUnnamedRoute('/admin/users', [], ['tier' => 'admin']);

        $this->assertNull($resolver->resolve($route));
    }

    public function test_no_tier_no_name_does_not_throw(): void
    {
        // 没有 tier 也没有 name，不应触发 RouteMissingNameException
        // 非严格模式下应正常返回 null
        $resolver = new TierResolver([], null, strictMode: true);

        $route = $this->makeUnnamedRoute('/some/uri', [], []);

        // 无 tier 无 name 在 strict 模式下走的是 RouteTierNotAssignedException 路径
        // 但前提是没有命中任何层级——这里空配置，所以会走兖底逻辑
        // strict_mode=true 且无 fallback → 抛 RouteTierNotAssignedException
        $this->expectException(\RouteForge\Laravel\Exceptions\RouteTierNotAssignedException::class);
        $resolver->resolve($route);
    }

    // ---------------------------------------------------------------------
    // classifier 返回未知层级名验证
    // ---------------------------------------------------------------------

    public function test_classifier_returns_unknown_tier_throws_exception(): void
    {
        $levels = ['admin' => ['match' => []], 'public' => ['match' => []]];
        $classifier = fn($route) => 'nonexistent_tier';
        $resolver = new TierResolver($levels, $classifier);

        $route = $this->makeRoute('/test', [], []);

        $this->expectException(UnknownClassifierTierException::class);
        $resolver->resolve($route);
    }

    public function test_classifier_returns_valid_tier_works(): void
    {
        $levels = ['admin' => ['match' => []], 'public' => ['match' => []]];
        $classifier = fn($route) => 'admin';
        $resolver = new TierResolver($levels, $classifier);

        $route = $this->makeRoute('/test', [], []);

        $this->assertSame('admin', $resolver->resolve($route));
    }

    public function test_classifier_returns_null_falls_through(): void
    {
        $levels = ['admin' => ['match' => ['prefix' => ['admin']]]];
        $classifier = fn($route) => null;
        $resolver = new TierResolver($levels, $classifier);

        $route = $this->makeRoute('admin/users', [], []);

        // classifier 返回 null，继续走配置 match 规则
        $this->assertSame('admin', $resolver->resolve($route));
    }
}
