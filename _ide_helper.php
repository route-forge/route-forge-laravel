<?php

/**
 * IDE Helper for Route Forge Laravel.
 *
 * 本文件为静态分析工具（PHPStorm / Intelephense / Larastan）提供
 * `->tier()` 宏与 `Route::group(['tier' => ...])` 的类型提示。
 * 运行时不会加载此文件。
 *
 * @see \RouteForge\Laravel\ForgeServiceProvider::registerTierMacro()
 * @see \RouteForge\Laravel\ForgeRouter
 */

namespace Illuminate\Routing {

    /**
     * Route Forge 扩展：为 Route 实例添加 ->tier() 链式方法。
     *
     * 用法示例：
     * ```php
     * Route::get('/admin/users', [AdminController::class, 'index'])->tier('admin');
     * ```
     */
    class Route
    {
        /**
         * 设置该路由所属的层级（tier）。
         *
         * 将 tier 写入 route action 数组，供 TierResolver 解析。
         * 优先级高于 group 透传与 config match，是最高优先级的 tier 分配方式。
         *
         * @param string $tier 层级标识（如 'admin'、'app'、'public' 等，需与 forge.levels 配置键一致）
         * @return $this
         */
        public function tier(string $tier): static
        {
            return $this;
        }
    }

    /**
     * Route Forge 扩展：Router 支持 tier 链式调用与 group 'tier' 属性。
     *
     * 用法示例：
     * ```php
     * // 数组语法
     * Route::group(['tier' => 'admin', 'prefix' => 'admin'], function ($router) {
     *     $router->get('/users', [AdminController::class, 'index'])->name('admin.users');
     * });
     *
     * // 链式语法
     * Route::tier('admin')->prefix('admin')->group(function ($router) {
     *     $router->get('/users', [AdminController::class, 'index'])->name('admin.users');
     * });
     *
     * // group 后链式 tier
     * Route::group(['prefix' => 'admin'], function () { ... })->tier('admin');
     * ```
     */
    class Router
    {
        /**
         * 创建路由组。
         *
         * @param array{tier?:string,prefix?:string,as?:string,middleware?:string|array,namespace?:string,domain?:string,where?:array} $attributes
         * @param \Closure|array|string $routes
         * @return static
         */
        public function group($attributes, $routes)
        {
            return $this;
        }

        /**
         * 设置路由组的 tier（链式语法入口）。
         *
         * 通过 Router::__call → ForgeRouteRegistrar 实现，
         * 返回 Registrar 实例，可继续链式调用 middleware/prefix/as/group 等。
         *
         * @param string $tier 层级标识（如 'admin'、'manage'、'client'、'public' 等）
         * @return \Illuminate\Routing\RouteRegistrar
         */
        public function tier(string $tier)
        {
            // IDE helper stub
        }
    }
}
