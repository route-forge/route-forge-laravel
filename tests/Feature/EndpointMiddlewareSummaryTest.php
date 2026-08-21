<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 摘要端点中间件保护测试（对应 .docs/SPEC.md §5 endpoint_middleware）。
 *
 * 覆盖：
 *   1. 摘要端点配置 endpoint_middleware 后，未通过中间件返回 403
 *   2. 层级端点不受摘要端点中间件影响
 */
class EndpointMiddlewareSummaryTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('forge.endpoint_middleware', [ForgeTestBlockMiddleware::class]);
    }

    private function summaryEndpoint(): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    private function levelEndpoint(string $level): string
    {
        return $this->summaryEndpoint() . '/' . $level;
    }

    public function test_summary_endpoint_with_middleware_is_blocked(): void
    {
        $response = $this->get($this->summaryEndpoint());

        $response->assertStatus(403);
    }

    public function test_level_endpoint_unaffected_by_summary_middleware(): void
    {
        RouteFacade::get('/public/info', static function () {})
            ->name('public.info')
            ->tier('public');

        $response = $this->get($this->levelEndpoint('public'));

        $response->assertStatus(200);
    }
}
