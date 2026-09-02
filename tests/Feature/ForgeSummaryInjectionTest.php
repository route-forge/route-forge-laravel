<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route as RouteFacade;
use Orchestra\Testbench\TestCase;
use RouteForge\Laravel\ForgeServiceProvider;

/**
 * 首页内嵌摘要 Blade 指令 @forgeSummary 测试（对应 .docs/SPEC.md §3.1.8）。
 *
 * 覆盖验收标准：
 *   1. 输出为 defineProperty 一次性、不可枚举、读后自删的 window 访问器结构
 *   2. 注入的摘要与 GET 摘要端点响应逐字段等值（复用同一 producer）
 *   3. </script> 逃逸被安全编码中和（数据里不出现可截断脚本的标签序列）
 *   4. 未书写指令的页面不产出注入脚本，且摘要端点无回归（SPA / Vite dev 回落网络摘要）
 */
class ForgeSummaryInjectionTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [ForgeServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // dev 模式：缓存读写自动旁路，扫描结果即时反映当前配置，测试确定性
        $app['config']->set('app.debug', true);
    }

    private function summaryEndpoint(): string
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');

        return '/' . ltrim(rtrim($prefix, '/'), '/');
    }

    /**
     * 从渲染出的 <script> 里还原被内嵌的摘要数组。
     *
     * Js::from 对数组输出 JSON.parse('<经过 json_encode 转义的 JSON 文本>')；
     * 两段解码：先把单引号 JS 字面量还原成 JSON 文本，再解码为 PHP 数组。
     * 与 HTTP 响应比较时忽略转义差异（口径来自交接验收标准）。
     */
    private function extractInjected(string $script): array
    {
        if (preg_match("/JSON\.parse\('(.*)'\)/s", $script, $m) !== 1) {
            $this->fail('注入脚本中未找到 JSON.parse 载荷');
        }

        $jsonText = json_decode('"' . $m[1] . '"', true, 512, JSON_THROW_ON_ERROR);

        return json_decode($jsonText, true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_directive_emits_one_time_accessor_shape(): void
    {
        RouteFacade::get('/public/ping', static fn () => null)
            ->name('public.ping')->tier('public');

        $script = Blade::render('@forgeSummary');

        $this->assertStringContainsString(
            "Object.defineProperty(window, '__ROUTE_FORGE__', {",
            $script,
        );
        $this->assertStringContainsString('configurable: true', $script);
        $this->assertStringContainsString('enumerable: false', $script);
        $this->assertStringContainsString('get: function () {', $script);
        $this->assertStringContainsString('delete window.__ROUTE_FORGE__;', $script);
    }

    public function test_injected_summary_equals_summary_endpoint_field_for_field(): void
    {
        RouteFacade::get('/public/ping', static fn () => null)
            ->name('public.ping')->tier('public');

        $endpoint = $this->getJson($this->summaryEndpoint())->assertStatus(200)->json();
        $injected = $this->extractInjected(Blade::render('@forgeSummary'));

        $this->assertEquals($endpoint, $injected);
    }

    public function test_script_tag_injection_is_neutralized(): void
    {
        // 把 </script><script>alert(1)</script> 塞进会流入摘要的字段（层级 description）
        config(['forge.levels.public.description' => '</script><script>alert(1)</script>']);

        $script = Blade::render('@forgeSummary');

        // 全文只应保留渲染器自身的一对 <script>/</script>；数据里的标签已被转义
        $this->assertSame(1, substr_count($script, '<script>'));
        $this->assertSame(1, substr_count($script, '</script>'));
        // JSON_HEX_TAG 生效：< / > 被转义为 \u003C / \u003E
        $this->assertStringContainsString('\\u003C', $script);
        $this->assertStringContainsString('\\u003E', $script);
    }

    public function test_page_without_directive_emits_no_injection_and_endpoint_intact(): void
    {
        RouteFacade::get('/public/ping', static fn () => null)
            ->name('public.ping')->tier('public');

        // 纯 SPA / 未书写指令的 Blade 页面：不产出任何 __ROUTE_FORGE__ 痕迹
        $html = Blade::render('<div>plain page</div>');
        $this->assertStringNotContainsString('__ROUTE_FORGE__', $html);

        // 摘要端点原样保留、无回归
        $this->getJson($this->summaryEndpoint())->assertStatus(200);
    }
}
