<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use Illuminate\Routing\RouteRegistrar as BaseRouteRegistrar;

/**
 * Forge 扩展的 RouteRegistrar：将 `tier` 加入 allowedAttributes 白名单，
 * 让 Route::group()->tier('admin') 链式调用成为合法语法。
 *
 * 背景：Laravel 原生 RouteRegistrar::__call() 会检查 allowedAttributes，
 * 不在白名单的属性会抛出 InvalidArgumentException。
 * 通过继承并扩展白名单，tier 可以像 middleware/prefix 等一样作为链式方法调用。
 *
 * @method \Illuminate\Routing\RouteRegistrar tier(string $tier)
 * @method \Illuminate\Routing\RouteRegistrar as(string $value)
 * @method \Illuminate\Routing\RouteRegistrar middleware(array|string|null $middleware)
 * @method \Illuminate\Routing\RouteRegistrar prefix(string $prefix)
 * @method \Illuminate\Routing\RouteRegistrar domain(\BackedEnum|string $value)
 * @method \Illuminate\Routing\RouteRegistrar name(\BackedEnum|string $value)
 * @method \Illuminate\Routing\RouteRegistrar namespace(string|null $value)
 * @method \Illuminate\Routing\RouteRegistrar controller(string $controller)
 * @method \Illuminate\Routing\RouteRegistrar where(array $where)
 * @method \Illuminate\Routing\RouteRegistrar can(\UnitEnum|string $ability, array|string $models = [])
 * @method \Illuminate\Routing\RouteRegistrar metadata(array $metadata)
 * @method \Illuminate\Routing\RouteRegistrar missing(\Closure $missing)
 * @method \Illuminate\Routing\RouteRegistrar scopeBindings()
 * @method \Illuminate\Routing\RouteRegistrar withoutMiddleware(array|string $middleware)
 * @method \Illuminate\Routing\RouteRegistrar withoutScopedBindings()
 * @method \Illuminate\Routing\RouteRegistrar group(\Closure|array|string $callback)
 * @method \Illuminate\Routing\Route resource(string $name, string $controller, array $options = [])
 * @method \Illuminate\Routing\Route apiResource(string $name, string $controller, array $options = [])
 * @method \Illuminate\Routing\Route get(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route post(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route put(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route patch(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route delete(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route options(string $uri, \Closure|array|string|null $action = null)
 * @method \Illuminate\Routing\Route any(string $uri, \Closure|array|string|null $action = null)
 */
class ForgeRouteRegistrar extends BaseRouteRegistrar
{
    /**
     * The attributes that can be set through this class.
     *
     * @var string[]
     */
    protected $allowedAttributes = [
        'as',
        'can',
        'controller',
        'domain',
        'metadata',
        'middleware',
        'missing',
        'name',
        'namespace',
        'prefix',
        'scopeBindings',
        'tier',
        'where',
        'withoutMiddleware',
        'withoutScopedBindings',
    ];
}
