<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Blade;

use Illuminate\Support\Js;
use RouteForge\Laravel\RouteRepository;

/**
 * 首页内嵌摘要渲染器：把「摘要端点」的返回值以一段 &lt;script&gt; 内嵌进
 * 服务端渲染（Blade）的 HTML，供前端 @route-forge/core 在浏览器初始化时直接消费，
 * 跳过首屏的一次摘要 HTTP 往返。
 *
 * 契约（对齐前端消费实现，勿单方面变更）：
 *   - 暴露的全局 key 固定为 {@see self::GLOBAL_KEY}。
 *   - 值 = 与 GET 摘要端点逐字段一致的 JSON（复用同一 producer，见下）。
 *   - 定义方式：defineProperty 一次性 getter，读后即 delete、enumerable:false、
 *     configurable:true。语义 = 前端读一次拿到摘要、访问器自删、window 上不再残留。
 *
 * 红线（务必保持）：
 *   1. producer 复用：摘要只来自 {@see RouteRepository::getSummary()}，与摘要端点
 *      同一份内存结构，不另起扫描；缓存（RouteCache）、dev 旁路、包自身路由排除
 *      等既有语义因此自动继承，杜绝前后端契约漂移。
 *   2. 只嵌摘要，绝不嵌层级路由表：受保护层级的路由明细不得预置进公开 HTML，
 *      各层级仍按 level 走 HTTP 懒加载（这是产品的"懒加载 + 受保护"卖点）。
 *   3. XSS 安全编码：内嵌 JSON 必须经 Js::from（内部带 JSON_HEX_TAG 等），
 *      防 </script> 逃逸，禁止裸 json_encode 直拼。
 *   4. 不递增 schemeVersion：这是既有摘要契约的"投递方式"扩展，非协议变更。
 *
 * 安全边界（如实说明，勿夸大为加密/安全机制）：一次性自删只缩小数据在 window 上的
 * 运行时驻留面；摘要数据仍随 HTML 源码可见，非抗 XSS / 抗网络窃取的硬边界。
 *
 * @see .docs/SPEC.md §3.1.6（摘要结构）/ §3.1.8（内嵌投递方式）
 * @see .docs/DESIGN.md §6.3（为何把 Blade 注入重新定义为"可选首页加速"）
 */
final class ForgeSummaryRenderer
{
    /**
     * 前端约定消费的全局 key（勿改，与 @route-forge/core 消费实现对齐）。
     */
    public const GLOBAL_KEY = '__ROUTE_FORGE__';

    public function __construct(
        private readonly RouteRepository $repository,
    ) {
    }

    /**
     * 渲染可直接放进 HTML &lt;head&gt;（早于前端 bundle）的一段 &lt;script&gt;。
     *
     * 返回的是已安全编码的原始 HTML 字符串（内含 JSON.parse('...') 表达式，
     * 其中的 &lt; / &gt; 已被 Js::from 转义），调用方应原样 echo，勿再经 Blade {{ }} 转义。
     */
    public function render(): string
    {
        // 复用摘要端点同一 producer：字段契约 / 缓存 / 包路由排除 / dev 旁路全部继承
        $summary = $this->repository->getSummary();

        // Js::from 对数组/对象输出 JSON.parse('...') 形态的安全 JS 表达式，
        // 内部已带 JSON_HEX_TAG|JSON_HEX_QUOT|... ，</script> 无法逃逸
        $expression = Js::from($summary)->toHtml();

        $key = self::GLOBAL_KEY;

        return "<script>\n"
            . "Object.defineProperty(window, '{$key}', {\n"
            . "  configurable: true,\n"
            . "  enumerable: false,\n"
            . "  get: function () {\n"
            . "    var v = {$expression};\n"
            . "    delete window.{$key};\n"
            . "    return v;\n"
            . "  }\n"
            . "});\n"
            . "</script>";
    }
}
