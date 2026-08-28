<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 自定义 endpoint_prefix 测试（对应 .docs/SPEC.md §5.1 / §3.1.5-§3.1.6）。
 *
 * 覆盖：
 *   1. 层级端点与摘要端点注册在自定义前缀下
 *   2. 摘要中各层级的 route.uri 自描述使用自定义前缀
 *   3. 前缀规范化：缺失前导斜杠 / 多余尾部斜杠均被纠正，不产生双斜杠
 */
class EndpointPrefixTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        // 故意使用「无前导斜杠 + 带尾部斜杠」的脏前缀，验证规范化
        $app['config']->set('forge.endpoint_prefix', 'api/forge/');
    }

    public function test_level_endpoint_registered_under_normalized_custom_prefix(): void
    {
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        // 'api/forge/' → 规范化为 '/api/forge'
        $response = $this->get('/api/forge/admin');
        $response->assertStatus(200);
        $this->assertSame('admin', $response->json('level'));
        $this->assertArrayHasKey('admin.users.index', $response->json('routes'));
    }

    public function test_summary_endpoint_under_custom_prefix_self_describes_uris(): void
    {
        $summary = $this->get('/api/forge')->json();

        $this->assertSame('/api/forge/admin', $summary['levels']['admin']['route']['uri']);
        $this->assertSame('/api/forge/unassigned', $summary['levels']['unassigned']['route']['uri']);
        // config.endpoint_prefix 回显原始配置值
        $this->assertSame('api/forge/', $summary['config']['endpoint_prefix']);
    }

    public function test_default_prefix_not_leaked_when_custom_set(): void
    {
        // 自定义前缀生效后，默认 '/_forge/routes' 不应再注册
        $this->get('/_forge/routes/admin')->assertStatus(404);
    }
}
