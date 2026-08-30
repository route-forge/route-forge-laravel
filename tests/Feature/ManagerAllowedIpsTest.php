<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 管理器页面 IP 白名单测试（manager_allowed_ips，对应 .docs/SPEC.md §3.3）。
 *
 * 访问控制两层：
 *   1. APP_DEBUG=false 不注册管理器路由（见 ForgeManagerControllerTest 相关约定）；
 *   2. 开发环境内 ManagerAllowedIps 中间件按 IP 白名单放行/拒绝。
 *
 * 测试请求的来源 IP 经 server 参数 REMOTE_ADDR 指定
 * （Symfony Request::create 默认为 127.0.0.1）。
 */
class ManagerAllowedIpsTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // 管理器路由仅开发环境注册
        $app['config']->set('app.debug', true);
    }

    public function test_default_config_allows_loopback_only(): void
    {
        // 默认 ['127.0.0.1', '::1']：本机放行（含 IPv6 回环）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '127.0.0.1'])->assertStatus(200);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '::1'])->assertStatus(200);

        // 外部来源 403（页面与 API 一并守卫）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
        $this->get('/_forge/manager/api/routes', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
        $this->get('/_forge/manager/api/config', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(403);
    }

    public function test_configured_lan_ip_is_allowed(): void
    {
        // 局域网调试：追加开发机局域网 IP 后可从该来源访问
        config()->set('forge.manager_allowed_ips', ['127.0.0.1', '::1', '192.168.1.10']);

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '192.168.1.10'])->assertStatus(200);
        // 列表外的其它局域网 IP 仍被拒绝（精确匹配，非网段）
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '192.168.1.99'])->assertStatus(403);
    }

    public function test_wildcard_allows_any_ip(): void
    {
        config()->set('forge.manager_allowed_ips', ['*']);

        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);
    }

    public function test_empty_or_null_list_disables_ip_check(): void
    {
        // 空数组 / null = 开发者显式放开 IP 限制（文档已警示暴露风险）
        config()->set('forge.manager_allowed_ips', []);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);

        config()->set('forge.manager_allowed_ips', null);
        $this->get('/_forge/manager', ['REMOTE_ADDR' => '203.0.113.7'])->assertStatus(200);
    }

    public function test_default_request_without_remote_addr_header_still_passes(): void
    {
        // 不带 REMOTE_ADDR 的测试请求默认来源即 127.0.0.1，
        // 默认配置下应当放行（同时保证既有管理器用例不受影响）
        $this->get('/_forge/manager')->assertStatus(200);
    }
}
