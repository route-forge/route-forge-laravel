<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\Exceptions\UnknownLevelException;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 资源路由 tier 链式调用测试（对应 .docs/SPEC.md §3.1.1）。
 *
 * 覆盖：
 *   1. Route::resource(...)->tier('x') 对组内全部路由生效
 *   2. Route::apiResource(...)->tier('x') 同样生效
 *   3. Route::singleton(...)->tier('x') 同样生效
 *   4. 非法层级名在链式调用时立即抛 UnknownLevelException
 *   5. 资源级 tier 优先于 Route::group 透传的 tier（显式 > 分组，SPEC §3.1.4）
 *   6. group tier 对无显式 tier 的资源路由正常透传
 */
class ResourceTierTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    public function test_resource_tier_applies_to_all_resource_routes(): void
    {
        RouteFacade::resource('users', 'App\Http\Controllers\UserController')
            ->tier('admin');

        $routes = $this->get($this->endpoint('admin'))->json('routes');

        // resource 默认注册 7 条路由（index/create/store/show/edit/update/destroy）
        foreach (['users.index', 'users.create', 'users.store', 'users.show', 'users.edit', 'users.update', 'users.destroy'] as $name) {
            $this->assertArrayHasKey($name, $routes, "resource 路由 [{$name}] 应归属 admin 层级");
        }
    }

    public function test_api_resource_tier_applies(): void
    {
        RouteFacade::apiResource('posts', 'App\Http\Controllers\PostController')
            ->tier('manage');

        $routes = $this->get($this->endpoint('manage'))->json('routes');

        // apiResource 默认注册 5 条路由（index/store/show/update/destroy）
        foreach (['posts.index', 'posts.store', 'posts.show', 'posts.update', 'posts.destroy'] as $name) {
            $this->assertArrayHasKey($name, $routes);
        }
        $this->assertArrayNotHasKey('posts.create', $routes);
    }

    public function test_singleton_resource_tier_applies(): void
    {
        RouteFacade::singleton('profile', 'App\Http\Controllers\ProfileController')
            ->tier('client');

        $routes = $this->get($this->endpoint('client'))->json('routes');

        $this->assertArrayHasKey('profile.show', $routes);
        $this->assertArrayHasKey('profile.update', $routes);
    }

    public function test_unknown_tier_throws_immediately(): void
    {
        $this->expectException(UnknownLevelException::class);

        RouteFacade::resource('users', 'App\Http\Controllers\UserController')
            ->tier('nonexistent');
    }

    public function test_resource_tier_overrides_group_tier(): void
    {
        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::resource('users', 'App\Http\Controllers\UserController')
                ->tier('client');
        });

        // 资源级 tier（显式标注）优先于 group 透传（SPEC §3.1.4）
        $clientRoutes = $this->get($this->endpoint('client'))->json('routes');
        $this->assertArrayHasKey('users.index', $clientRoutes);

        $adminRoutes = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayNotHasKey('users.index', $adminRoutes);
    }

    public function test_group_tier_applies_to_resource_without_explicit_tier(): void
    {
        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::resource('users', 'App\Http\Controllers\UserController');
        });

        $adminRoutes = $this->get($this->endpoint('admin'))->json('routes');
        $this->assertArrayHasKey('users.index', $adminRoutes);
    }

    private function endpoint(string $level): string
    {
        return '/_forge/routes/' . $level;
    }
}
