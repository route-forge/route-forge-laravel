<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 无端点中间件配置测试：验证默认行为不受影响。
 *
 * 覆盖：
 *   1. 未配置任何 endpoint_middleware 时，所有端点正常可访问
 *   2. 兜底路由仍正常工作，未知层级返回 404
 */
class EndpointMiddlewareNoneTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('forge.endpoint_middleware', []);
    }

    private function endpoint(string $level): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        return '/' . ltrim(rtrim($prefix, '/'), '/') . '/' . $level;
    }

    private function summaryEndpoint(): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    public function test_all_endpoints_accessible_without_middleware(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        $this->get($this->endpoint('admin'))->assertStatus(200);
        $this->get($this->summaryEndpoint())->assertStatus(200);
    }

    public function test_unknown_level_still_returns_404(): void
    {
        $response = $this->get($this->endpoint('nonexistent'));

        $response->assertStatus(404);
        $this->assertSame('RF_BE_002', $response->json('error.code'));
    }
}
