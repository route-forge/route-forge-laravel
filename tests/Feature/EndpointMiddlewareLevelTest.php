<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 层级端点中间件保护测试（对应 .docs/SPEC.md §3.1.5 端点中间件保护）。
 *
 * 覆盖：
 *   1. 层级端点配置 endpoint_middleware 后，未通过中间件返回 403
 *   2. 层级端点未配置 endpoint_middleware 时正常返回 200
 */
class EndpointMiddlewareLevelTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('forge.levels.admin.endpoint_middleware', [ForgeTestBlockMiddleware::class]);
    }

    private function endpoint(string $level): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        return '/' . ltrim(rtrim($prefix, '/'), '/') . '/' . $level;
    }

    public function test_level_endpoint_with_middleware_is_blocked(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $response = $this->get($this->endpoint('admin'));

        $response->assertStatus(403);
    }

    public function test_level_endpoint_without_middleware_is_accessible(): void
    {
        RouteFacade::get('/public/info', static function () {})
            ->name('public.info')
            ->tier('public');

        $response = $this->get($this->endpoint('public'));

        $response->assertStatus(200);
        $this->assertArrayHasKey('routes', $response->json());
    }
}
