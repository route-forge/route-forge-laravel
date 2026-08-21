<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Feature;

use Closure;
use Illuminate\Http\Request;

/**
 * 测试用中间件：直接返回 403 模拟拦截。
 * 供 EndpointMiddleware* 系列测试共用。
 */
class ForgeTestBlockMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return response('Blocked by middleware', 403);
    }
}
