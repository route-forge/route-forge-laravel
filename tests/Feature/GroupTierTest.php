<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\Exceptions\DiscardedRegistrarAttributesException;
use RouteForge\Laravel\Exceptions\UnknownLevelException;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * Tier 分配端到端验证（对应 .docs/SPEC.md §3.1.1, §3.1.3, §3.1.4）。
 *
 * 覆盖：
 *   - 显式 `->tier()` 宏
 *   - Route::group(['tier' => ...]) 透传
 *   - 嵌套 group：内层 tier 覆盖外层 tier（与 middleware 合并策略一致）
 *   - 优先级：显式 `->tier()` 覆盖 group tier
 *
 * 注意：Testbench 中路由在测试方法体内注册（app 已 booted 之后），
 * RouteServiceProvider 的 `$app->booted` 回调（含 refreshNameLookups）
 * 此时已执行过，不会刷新测试内新增的路由。所以每次查找前需手动 refresh。
 */
class GroupTierTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    private function routeTier(?string $name): ?string
    {
        // 测试方法体内 `Route::post(...)->name(...)` 注册的路由不在 nameList 中
        // （addLookups 在 add() 时调用，那时 route 还没 name；chain 上的 ->name() 不刷新 nameList）。
        // RouteServiceProvider 的 $app->booted 回调已执行过，不会重刷。
        // 所以这里手动 refresh 一下。
        RouteFacade::getRoutes()->refreshNameLookups();

        $route = RouteFacade::getRoutes()->getByName($name);
        if (! $route instanceof Route) {
            return null;
        }
        $action = $route->getAction();
        return $action['tier'] ?? null;
    }

    public function test_explicit_tier_macro_sets_action(): void
    {
        RouteFacade::post('/auth/login', static function () {})
            ->name('auth.login')
            ->tier('public');

        $this->assertSame('public', $this->routeTier('auth.login'));
    }

    public function test_group_tier_is_propagated_to_routes_inside(): void
    {
        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::get('/admin/users', static function () {})
                ->name('admin.users.index');
            RouteFacade::post('/admin/users', static function () {})
                ->name('admin.users.store');
        });

        $this->assertSame('admin', $this->routeTier('admin.users.index'));
        $this->assertSame('admin', $this->routeTier('admin.users.store'));
    }

    public function test_nested_group_inner_tier_overrides_outer(): void
    {
        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::get('/a', static function () {})
                ->name('a');

            RouteFacade::group(['tier' => 'manage'], function () {
                RouteFacade::get('/b', static function () {})
                    ->name('b');
            });
        });

        $this->assertSame('admin', $this->routeTier('a'));
        $this->assertSame('manage', $this->routeTier('b'));
    }

    public function test_explicit_tier_overrides_group_tier(): void
    {
        RouteFacade::group(['tier' => 'admin'], function () {
            // 显式 ->tier() 优先级最高，覆盖 group 透传的 admin
            RouteFacade::get('/override', static function () {})
                ->name('override')
                ->tier('manage');
        });

        $this->assertSame('manage', $this->routeTier('override'));
    }

    public function test_routes_outside_any_group_have_no_tier(): void
    {
        RouteFacade::get('/standalone', static function () {})
            ->name('standalone');

        $this->assertNull($this->routeTier('standalone'));
    }

    public function test_fluent_tier_group_syntax(): void
    {
        // 测试 Route::tier('admin')->group(...) 链式语法
        RouteFacade::tier('admin')->group(function () {
            RouteFacade::get('/admin/dashboard', static function () {})
                ->name('admin.dashboard');
        });

        $this->assertSame('admin', $this->routeTier('admin.dashboard'));
    }

    public function test_fluent_middleware_and_tier_chained(): void
    {
        // 测试 Route::middleware([...])->tier('manage')->group(...) 链式语法
        RouteFacade::middleware('web')->tier('manage')->group(function () {
            RouteFacade::get('/manage/settings', static function () {})
                ->name('manage.settings');
        });

        $this->assertSame('manage', $this->routeTier('manage.settings'));
    }

    public function test_trailing_tier_after_group_is_discarded_with_warning(): void
    {
        // group(...) 执行完后组属性已出栈，尾部 ->tier() 挂在全新 Registrar 上，
        // 不作用于已注册的组；该 Registrar 销毁时应记录警告。
        Log::shouldReceive('warning')->once();

        RouteFacade::group(['as' => 'trailing.'], function () {
            RouteFacade::get('/trailing', static function () {})
                ->name('route');
        })->tier('public');

        // tier 已被丢弃：组内路由无 tier
        $this->assertNull($this->routeTier('trailing.route'));
    }

    public function test_fluent_tier_before_group_does_not_warn(): void
    {
        // 前置链式写法属性被正常消费，不应产生警告
        Log::shouldReceive('warning')->never();

        RouteFacade::tier('admin')->group(function () {
            RouteFacade::get('/silent', static function () {})
                ->name('silent');
        });

        $this->assertSame('admin', $this->routeTier('silent'));
    }

    public function test_trailing_tier_after_group_throws_in_strict_mode(): void
    {
        // strict_mode=true 时，尾部链式属性被丢弃应抛异常
        config(['forge.strict_mode' => true]);

        $this->expectException(DiscardedRegistrarAttributesException::class);

        RouteFacade::group(['as' => 'strict.'], function () {
            RouteFacade::get('/strict', static function () {})
                ->name('route');
        })->tier('public');
    }

    // ---------------------------------------------------------------------
    // tier 值必须在 levels 配置中验证
    // ---------------------------------------------------------------------

    public function test_fluent_tier_with_invalid_level_throws_exception(): void
    {
        // Route::tier('nonexistent') 链式调用时，tier 值不在 levels 配置中应抛异常
        $this->expectException(UnknownLevelException::class);

        RouteFacade::tier('nonexistent')->group(function () {
            RouteFacade::get('/test', static function () {});
        });
    }

    public function test_group_array_tier_with_invalid_level_throws_exception(): void
    {
        // Route::group(['tier' => 'nonexistent'], ...) 数组设置时，tier 值不在 levels 配置中应抛异常
        $this->expectException(UnknownLevelException::class);

        RouteFacade::group(['tier' => 'nonexistent'], function () {
            RouteFacade::get('/test', static function () {});
        });
    }

    public function test_explicit_tier_macro_with_invalid_level_throws_exception(): void
    {
        // 单条路由 ->tier('nonexistent') 宏：与其它所有 tier 入口一致，定义时立即校验（fail-fast）
        $this->expectException(UnknownLevelException::class);

        RouteFacade::get('/explicit', static function () {})
            ->name('explicit')
            ->tier('nonexistent');
    }

    public function test_fluent_tier_with_valid_level_works(): void
    {
        // Route::tier('admin') 链式调用，tier 值在 levels 配置中应正常工作
        Log::shouldReceive('warning')->never();

        RouteFacade::tier('admin')->group(function () {
            RouteFacade::get('/admin/test', static function () {})
                       ->name('admin.test');
        });

        $this->assertSame('admin', $this->routeTier('admin.test'));
    }

    public function test_group_array_tier_with_valid_level_works(): void
    {
        // Route::group(['tier' => 'manage'], ...) 数组设置，tier 值在 levels 配置中应正常工作
        RouteFacade::group(['tier' => 'manage'], function () {
            RouteFacade::get('/manage/test', static function () {})
                       ->name('manage.test');
        });

        $this->assertSame('manage', $this->routeTier('manage.test'));
    }

    public function test_nested_group_with_invalid_inner_tier_throws_exception(): void
    {
        // 嵌套 group 内层 tier 值不在 levels 配置中应抛异常
        $this->expectException(UnknownLevelException::class);

        RouteFacade::group(['tier' => 'admin'], function () {
            RouteFacade::group(['tier' => 'nonexistent'], function () {
                RouteFacade::get('/test', static function () {});
            });
        });
    }
}
