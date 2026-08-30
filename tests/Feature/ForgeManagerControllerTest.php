<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use ReflectionMethod;
use RouteForge\Laravel\ForgeServiceProvider;
use RouteForge\Laravel\Http\ForgeManagerController;
use RouteForge\Laravel\RouteRepository;

/**
 * 管理器页面控制器测试（对应 .docs/SPEC.md §3.3）。
 *
 * 仅开发环境（APP_DEBUG=true）注册管理器路由，故 defineEnvironment 开启 debug。
 * 覆盖三个只读端点 + 配置生成器的层级名转义安全性（防注入，php -l 实测）。
 */
class ForgeManagerControllerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RouteFacade::get('/admin/users', static function () {})
            ->name('admin.users.index')
            ->tier('admin');
        RouteFacade::get('/loose', static function () {})
            ->name('loose.route');
    }

    protected function tearDown(): void
    {
        // 清理 updateConfig 用例写入 testbench 骨架的配置文件，避免污染后续测试
        $written = $this->app->configPath('forge.php');
        if (is_file($written)) {
            @unlink($written);
        }

        parent::tearDown();
    }

    public function test_manager_page_renders_in_debug_mode(): void
    {
        $this->get('/_forge/manager')
            ->assertStatus(200)
            ->assertSee('Route Forge 管理器', false);
    }

    public function test_routes_api_returns_grouped_data_and_excludes_forge_routes(): void
    {
        $response = $this->get('/_forge/manager/api/routes');
        $response->assertStatus(200);

        $data = $response->json();
        $this->assertArrayHasKey('routes', $data);
        $this->assertArrayHasKey('tiers', $data);

        $names = array_column($data['routes'], 'name');
        $this->assertContains('admin.users.index', $names);
        $this->assertContains('loose.route', $names);
        // forge 自身路由（含管理器 API）不应出现在数据中
        foreach ($names as $n) {
            $this->assertStringStartsNotWith('forge.', $n);
        }
        // loose.route 无 tier → 归入 unassigned
        $this->assertArrayHasKey('unassigned', $data['tiers']);
    }

    public function test_config_api_returns_levels_and_global(): void
    {
        $payload = $this->get('/_forge/manager/api/config')->json();

        $this->assertArrayHasKey('levels', $payload);
        $this->assertArrayHasKey('global', $payload);
        $this->assertArrayHasKey('admin', $payload['levels']);
        // global 不再包含已移除的 fallback_level
        $this->assertArrayNotHasKey('fallback_level', $payload['global']);
    }

    public function test_update_config_preserves_endpoint_middleware(): void
    {
        // 修复前：生成器硬编码 'endpoint_middleware' => []，保存即静默丢失现有配置
        config()->set('forge.endpoint_middleware', ['auth', 'throttle']);

        $response = $this->putJson('/_forge/manager/api/config', [
            'levels' => config('forge.levels'),
            'global' => [
                'endpoint_prefix' => '/_forge/routes',
                'cache_ttl'       => 60,
                'strict_mode'     => false,
            ],
        ]);

        $response->assertStatus(200);

        $written = (string) file_get_contents($this->app->configPath('forge.php'));
        $this->assertStringContainsString("'endpoint_middleware' => ['auth', 'throttle']", $written);
    }

    public function test_update_config_refuses_when_classifier_configured(): void
    {
        // classifier 是闭包，无法序列化回 PHP 配置文件；
        // 修复前保存会静默抹掉该回调，现改为拒绝保存并明确提示
        config()->set('forge.classifier', static fn ($route) => null);

        $response = $this->putJson('/_forge/manager/api/config', [
            'levels' => config('forge.levels'),
            'global' => ['cache_ttl' => 60],
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('classifier', (string) $response->json('error'));
        $this->assertFileDoesNotExist($this->app->configPath('forge.php'));
    }

    public function test_generated_config_is_valid_php_and_escapes_level_names(): void
    {
        $controller = new ForgeManagerController($this->app->make(RouteRepository::class));
        $method = new ReflectionMethod($controller, 'generateConfigContent');
        $method->setAccessible(true);

        // 恶意层级名：尝试闭合数组并注入语句
        $evil = "x'] ; passthru('id'); //";
        $levels = [
            $evil => ['description' => "desc'quote", 'match' => ['prefix' => ['p']], 'load' => 'lazy'],
        ];

        /** @var string $php */
        $php = $method->invoke($controller, $levels, ['cache_ttl' => 60]);

        // 1) 生成的内容必须是语法合法的 PHP（写临时文件后 php -l 校验）
        $tmp = tempnam(sys_get_temp_dir(), 'forge-cfg-') . '.php';
        file_put_contents($tmp, $php);
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
        @unlink($tmp);
        $this->assertSame(0, $code, '生成的配置文件必须是合法 PHP：' . implode("\n", $out));

        // 2) 层级名必须经 var_export 转义：出现未转义的裸插值形式即为注入漏洞
        //    （修复前的写法 "{$i}'{$name}' => [" 会产出如下可闭合数组的危险串）
        $this->assertStringNotContainsString("{$evil}' => [", $php);
        // 转义后仍保留原始字符（作为字符串字面量内容），且 php -l 已证明整体合法
        $this->assertStringContainsString('passthru', $php);
    }
}
