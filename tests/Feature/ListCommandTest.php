<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * route:forge:list 命令测试（对应 .docs/SPEC.md §3.2）。
 *
 * 默认配置：strict_mode=false、levels=[public, client, manage, admin]，
 * 未命中层级的路由归入 unassigned 特殊层级（fallback_level 已移除）。
 * ForgeServiceProvider::boot() 会注册 forge.routes.{index,show} 元信息端点，
 * 命令内部按 str_starts_with('forge.routes.') 跳过它们，故不会污染输出。
 *
 * 说明：使用 Kernel::call + BufferedOutput 捕获完整输出后用 assertStringContainsString
 * 断言，避免 expectsOutputToContain 在「单次 doWrite 输出多行」时仅能匹配首个子串的限制。
 */
class ListCommandTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // admin 层级：显式 tier
        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');

        // client 层级：显式 tier
        RouteFacade::get('/client/dashboard', static function () {})
            ->name('client.dashboard')
            ->tier('client');

        // orphan：未声明 tier、不匹配任何 prefix/middleware → unassigned
        RouteFacade::get('/orphan', static function () {})
            ->name('orphan');
    }

    /**
     * @return array{0:int,1:string}
     */
    private function runList(array $params = []): array
    {
        $buffer = new BufferedOutput();
        $exit = $this->app->make(Kernel::class)->call('route:forge:list', $params, $buffer);

        return [$exit, $buffer->fetch()];
    }

    public function test_default_lists_all_routes_with_table_header(): void
    {
        [$exit, $out] = $this->runList();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Name', $out);
        $this->assertStringContainsString('Level', $out);
        $this->assertStringContainsString('Methods', $out);
        $this->assertStringContainsString('URI', $out);
        $this->assertStringContainsString('admin.users.index', $out);
        $this->assertStringContainsString('client.dashboard', $out);
        $this->assertStringContainsString('orphan', $out);
    }

    public function test_level_filter_only_lists_matching_routes(): void
    {
        [$exit, $out] = $this->runList(['--level' => 'admin']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('admin.users.index', $out);
        $this->assertStringContainsString('admin', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
        $this->assertStringNotContainsString('orphan', $out);
    }

    public function test_level_filter_unknown_level_shows_available(): void
    {
        [$exit, $out] = $this->runList(['--level' => 'nonexistent']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Unknown level', $out);
        $this->assertStringContainsString('Available levels', $out);
        // 可用层级应被列出
        $this->assertStringContainsString('public', $out);
        $this->assertStringContainsString('admin', $out);
    }

    public function test_json_output_format(): void
    {
        [$exit, $out] = $this->runList(['--json' => true]);

        $this->assertSame(0, $exit);

        $decoded = json_decode($out, true);
        $this->assertIsArray($decoded, 'JSON 输出应为数组');

        // 结构化输出：含 levels / count / routes 字段
        $this->assertArrayHasKey('levels', $decoded);
        $this->assertArrayHasKey('count', $decoded);
        $this->assertArrayHasKey('routes', $decoded);
        $this->assertNull($decoded['filter'], '无过滤条件时 filter 为 null');
        $this->assertSame(3, $decoded['count']);

        // levels 应包含已定义层级 + unassigned 特殊层级
        $this->assertContains('admin', $decoded['levels']);
        $this->assertContains('unassigned', $decoded['levels']);

        // routes 为路由条目数组
        $routes = $decoded['routes'];
        $names  = array_column($routes, 'name');
        $this->assertContains('admin.users.index', $names);
        $this->assertContains('client.dashboard', $names);
        $this->assertContains('orphan', $names);

        // 验证条目结构
        $adminKey = array_search('admin.users.index', $names, true);
        $admin  = $routes[$adminKey];
        $this->assertSame('admin', $admin['level']);
        $this->assertSame(['GET'], $admin['methods']);
        $this->assertSame('admin/users', $admin['uri']);

        // orphan 未分配 → level='unassigned'（不再为 null）
        $orphanKey = array_search('orphan', $names, true);
        $this->assertSame('unassigned', $routes[$orphanKey]['level']);
    }

    public function test_unassigned_filter_lists_only_unassigned(): void
    {
        // 未命中层级的命名路由归入 unassigned（唯一的兜底机制）
        [$exit, $out] = $this->runList(['--unassigned' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('orphan', $out);
        $this->assertStringNotContainsString('admin.users.index', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
    }

    public function test_level_filter_accepts_unassigned_special_level(): void
    {
        // --level=unassigned 与 --unassigned 语义一致（特殊层级可作为过滤值）
        [$exit, $out] = $this->runList(['--level' => 'unassigned']);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('orphan', $out);
        $this->assertStringNotContainsString('admin.users.index', $out);
        $this->assertStringNotContainsString('client.dashboard', $out);
    }
}
