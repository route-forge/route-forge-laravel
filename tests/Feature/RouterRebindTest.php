<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Router as BaseRouter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeRouter;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * Router 重绑回归测试（对应 ForgeServiceProvider::rebindRouter()）。
 *
 * 背景（Laravel 11+ 骨架真实 HTTP 流程）：
 *   public/index.php → Application::handleRequest() → make(HttpKernel)，
 *   Http\Kernel::__construct(Application $app, Router $router) 在 bootstrap
 *   （RegisterProviders）之前就会解析并持有原生 Router 实例。随后
 *   ForgeServiceProvider::register() 才重绑 'router'：容器绑定虽已替换，
 *   Kernel 持有的仍是旧实例 —— 路由经 Route:: 门面注册进 ForgeRouter，
 *   分发却走旧 Router（空集合）→ 所有 URL 404。
 *
 * Testbench 的常规流程（provider 在 app 创建阶段注册、boot 完成后才派发
 * 测试请求）无法暴露该问题，所以这里手动复现「Kernel 提前捕获原生 Router」
 * 的时序：先以原生 Router 绑定并解析 'router'，再重新执行 register()。
 */
class RouterRebindTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    public function test_kernel_like_early_capture_shares_routes_collection(): void
    {
        // 模拟 Http\Kernel 构造函数先于 RegisterProviders 解析并捕获原生 Router
        $this->app->singleton('router', function ($app) {
            return new BaseRouter($app['events'], $app);
        });
        $captured = $this->app->make('router');
        $this->assertInstanceOf(BaseRouter::class, $captured);
        $this->assertNotInstanceOf(ForgeRouter::class, $captured);

        // 重新执行 register()，等价于 ForgeServiceProvider 在 RegisterProviders 阶段注册
        (new ForgeServiceProvider($this->app))->register();

        // 容器绑定已被替换为 ForgeRouter
        $forge = $this->app->make('router');
        $this->assertInstanceOf(ForgeRouter::class, $forge);

        // 关键回归：被 Kernel 捕获的原生 Router 与 ForgeRouter 共享 routes collection
        $this->assertSame($captured->getRoutes(), $forge->getRoutes());
    }

    public function test_dispatch_through_captured_native_router_still_matches(): void
    {
        // 同上：先让 'router' 被原生 Router 占据并被外部捕获
        $this->app->singleton('router', function ($app) {
            return new BaseRouter($app['events'], $app);
        });
        $captured = $this->app->make('router');

        (new ForgeServiceProvider($this->app))->register();

        // 经 Route:: 门面注册路由（走 ForgeRouter），同时验证 group tier 透传不受影响
        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::get('/captured/hello', static fn () => 'hello')
                ->name('captured.hello');
        });

        // 用被捕获的原生 Router 分发（等价 Kernel::dispatchToRouter），不得 404
        $response = $captured->dispatch(Request::create('/captured/hello', 'GET'));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('hello', $response->getContent());

        // group(['tier']) 透传仍然生效（注册链路走 ForgeRouter）
        // 注：链式 ->name() 不刷新 nameList（addLookups 在 add() 时已执行），需手动 refresh
        $captured->getRoutes()->refreshNameLookups();
        $route = $captured->getRoutes()->getByName('captured.hello');
        $this->assertNotNull($route);
        $this->assertSame('admin', $route->getAction()['tier'] ?? null);

        // 常规 HTTP 派发路径同样可达
        $this->get('/captured/hello')->assertOk();
    }

    public function test_rebind_without_prior_resolution_keeps_normal_flow(): void
    {
        // Testbench 常规流程：provider 注册时 'router' 尚未解析，
        // app('router') 应直接是 ForgeRouter（所有既有测试依赖此路径）
        $this->assertInstanceOf(ForgeRouter::class, $this->app->make('router'));
    }
}
