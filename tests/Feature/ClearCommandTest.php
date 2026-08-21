<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\Cache\RouteCache;
use RouteForge\Laravel\ForgeServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * route:forge:clear 命令测试（对应 .docs/SPEC.md §3.2）。
 *
 * 覆盖：
 *   1. 全量清除：无参数调用后所有层级缓存失效
 *   2. 按层级清除：--level=admin 仅失效 admin 缓存，其他层级不受影响
 *   3. 无效层级名返回非零退出码并提示可用层级
 */
class ClearCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // 测试缓存行为需要关闭 debug 模式
        $app['config']->set('app.debug', false);
        $app['config']->set('forge.cache_driver', 'array');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runClear(array $params = []): array
    {
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:clear', $params, $buffer);

        return [$exit, $buffer->fetch()];
    }

    private function seedCache(): RouteCache
    {
        $cache = $this->app->make(RouteCache::class);
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->set('public', ['level' => 'public', 'routes' => []]);
        $cache->set('summary', ['levels' => [], 'config' => [], 'unassigned' => []]);

        return $cache;
    }

    public function test_clear_all_removes_everything(): void
    {
        $cache = $this->seedCache();

        // 确认缓存已写入
        $this->assertNotNull($cache->get('admin'));
        $this->assertNotNull($cache->get('public'));

        [$exit, $out] = $this->runClear();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('cleared successfully', $out);

        // 全量清除后所有缓存失效
        $this->assertNull($cache->get('admin'));
        $this->assertNull($cache->get('public'));
        $this->assertNull($cache->get('summary'));
    }

    public function test_clear_specific_level_only_removes_that_level(): void
    {
        $cache = $this->seedCache();

        [$exit, $out] = $this->runClear(['--level' => 'admin']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('cleared for level: admin', $out);

        // admin 缓存被清除
        $this->assertNull($cache->get('admin'));
        // public 缓存不受影响
        $this->assertNotNull($cache->get('public'));
    }

    public function test_clear_unknown_level_shows_error(): void
    {
        [$exit, $out] = $this->runClear(['--level' => 'nonexistent']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown level', $out);
        $this->assertStringContainsString('Available levels', $out);
    }

    public function test_laravel_route_clear_also_clears_forge_cache(): void
    {
        $cache = $this->seedCache();

        // 确认缓存已写入
        $this->assertNotNull($cache->get('admin'));

        // 执行 Laravel 内置的 route:clear 命令
        $this->artisan('route:clear')->assertExitCode(0);

        // Route Forge 缓存应被连带清除
        $this->assertNull($cache->get('admin'));
        $this->assertNull($cache->get('public'));
    }
}
