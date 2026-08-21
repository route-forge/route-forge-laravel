<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use BackedEnum;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteRegistrar as BaseRouteRegistrar;
use UnitEnum;

/**
 * Forge 扩展的 RouteRegistrar：将 `tier` 加入 allowedAttributes 白名单，
 * 让 Route::group()->tier('admin') 链式调用成为合法语法。
 *
 * 背景：Laravel 原生 RouteRegistrar::__call() 会检查 allowedAttributes，
 * 不在白名单的属性会抛出 InvalidArgumentException。
 * 通过继承并扩展白名单，tier 可以像 middleware/prefix 等一样作为链式方法调用。
 *
 * @method BaseRouteRegistrar tier(string $tier)
 * @method BaseRouteRegistrar as(string $value)
 * @method BaseRouteRegistrar middleware(array|string|null $middleware)
 * @method BaseRouteRegistrar prefix(string $prefix)
 * @method BaseRouteRegistrar domain(BackedEnum|string $value)
 * @method BaseRouteRegistrar name(BackedEnum|string $value)
 * @method BaseRouteRegistrar namespace(string|null $value)
 * @method BaseRouteRegistrar controller(string $controller)
 * @method BaseRouteRegistrar where(array $where)
 * @method BaseRouteRegistrar can(UnitEnum|string $ability, array|string $models = [])
 * @method BaseRouteRegistrar metadata(array $metadata)
 * @method BaseRouteRegistrar missing(Closure $missing)
 * @method BaseRouteRegistrar scopeBindings()
 * @method BaseRouteRegistrar withoutMiddleware(array|string $middleware)
 * @method BaseRouteRegistrar withoutScopedBindings()
 * @method BaseRouteRegistrar group(Closure|array|string $callback)
 * @method Route resource(string $name, string $controller, array $options = [])
 * @method Route apiResource(string $name, string $controller, array $options = [])
 * @method Route get(string $uri, Closure|array|string|null $action = null)
 * @method Route post(string $uri, Closure|array|string|null $action = null)
 * @method Route put(string $uri, Closure|array|string|null $action = null)
 * @method Route patch(string $uri, Closure|array|string|null $action = null)
 * @method Route delete(string $uri, Closure|array|string|null $action = null)
 * @method Route options(string $uri, Closure|array|string|null $action = null)
 * @method Route any(string $uri, Closure|array|string|null $action = null)
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
