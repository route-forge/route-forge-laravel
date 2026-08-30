<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 管理器页面 IP 白名单守卫。
 *
 * 仅挂载在管理器路由（/_forge/manager*）上，按配置项
 * `forge.manager_allowed_ips` 限制可访问的来源 IP：
 *
 *   - IP 列表（精确匹配）：仅列表中的来源可访问；
 *     列表元素 '*' 表示放行任意来源；
 *   - null 或空数组：不做 IP 限制（开发者显式放开，
 *     局域网环境需注意暴露风险）；
 *   - 匹配失败 → 403。
 *
 * 默认配置 ['127.0.0.1', '::1'] 仅允许本机回环访问
 * （浏览器访问 localhost 时可能解析为 IPv6 的 ::1，故一并放行）。
 *
 * 生产环境无需依赖本守卫：APP_DEBUG=false 时管理器路由
 * 根本不注册（见 ForgeServiceProvider::registerManagerRoutes）。
 */
class ManagerAllowedIps
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = config('forge.manager_allowed_ips');

        // null 或空数组 = 不做 IP 限制（开发者显式放开）
        if (is_array($allowed) && count($allowed) > 0) {
            $ip  = (string) $request->ip();
            $hit = false;
            foreach ($allowed as $entry) {
                $entry = trim((string) $entry);
                if ($entry === '*' || $entry === $ip) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                abort(403, 'Route Forge manager is not accessible from this IP address.');
            }
        }

        return $next($request);
    }
}
