<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * classifier 契约回归测试（对应 SPEC §5：classifier 类型为 callable|null）。
 *
 * 重点：走完整容器绑定路径（config('forge.classifier') → ServiceProvider → TierResolver），
 * 验证「非 Closure 的合法 callable」不被静默丢弃。修复前 ServiceProvider 用
 * `instanceof Closure` 过滤，数组可调用 / 可调用对象会被当作 null 忽略。
 */
class ClassifierCallableTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
    }

    public function test_array_callable_classifier_is_honored(): void
    {
        // [Class::class, 'method'] 静态数组可调用
        config(['forge.classifier' => [ClassifierFixture::class, 'classify']]);

        RouteFacade::get('/classified/thing', static function () {})
            ->name('classified.thing');

        // classifier 把 classified/* 归入 admin（无显式 tier、不匹配任何 levels prefix）
        $routes = $this->get('/_forge/routes/admin')->json('routes');
        $this->assertArrayHasKey('classified.thing', $routes);
    }

    public function test_invokable_object_classifier_is_honored(): void
    {
        // 可调用对象（__invoke）
        config(['forge.classifier' => new InvokableClassifierFixture()]);

        RouteFacade::get('/invoked/thing', static function () {})
            ->name('invoked.thing');

        $routes = $this->get('/_forge/routes/client')->json('routes');
        $this->assertArrayHasKey('invoked.thing', $routes);
    }

    public function test_non_callable_classifier_is_treated_as_null(): void
    {
        // 非法 classifier（字符串标量，非 callable）→ 归一为 null，不抛错，走常规匹配
        config(['forge.classifier' => 'not-a-real-callable-fn-xyz']);

        RouteFacade::get('/admin/thing', static function () {})
            ->name('admin.thing')
            ->tier('admin');

        $routes = $this->get('/_forge/routes/admin')->json('routes');
        $this->assertArrayHasKey('admin.thing', $routes);
    }
}

/** 数组可调用 classifier fixture */
class ClassifierFixture
{
    public static function classify(Route $route): ?string
    {
        return str_starts_with($route->uri(), 'classified/') ? 'admin' : null;
    }
}

/** 可调用对象 classifier fixture */
class InvokableClassifierFixture
{
    public function __invoke(Route $route): ?string
    {
        return str_starts_with($route->uri(), 'invoked/') ? 'client' : null;
    }
}
