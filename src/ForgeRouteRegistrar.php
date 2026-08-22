<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use BackedEnum;
use Closure;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteRegistrar as BaseRouteRegistrar;
use Illuminate\Support\Facades\Log;
use RouteForge\Laravel\Exceptions\DiscardedRegistrarAttributesException;
use RouteForge\Laravel\Exceptions\UnknownLevelException;
use Throwable;
use UnitEnum;

/**
 * Forge 扩展的 RouteRegistrar：将 `tier` 加入 allowedAttributes 白名单，
 * 让 Route::tier('admin')->group(...) 链式调用成为合法语法。
 *
 * 背景：Laravel 原生 RouteRegistrar::__call() 会检查 allowedAttributes，
 * 不在白名单的属性会抛出 InvalidArgumentException。
 * 通过继承并扩展白名单，tier 可以像 middleware/prefix 等一样作为链式方法调用。
 *
 * ⚠️ 已知陷阱（Laravel 语义）：`Route::group(...)->tier('x')` 这类「group 之后追加属性」
 * 的写法中，group() 内部已完成组内路由注册并将组属性出栈，其后链式调用落在一个全新的
 * Registrar 上，属性不会作用于已注册的组，若无后续消费会被静默丢弃。
 * 本类通过 __destruct 检测「持有属性却从未注册 group/路由」的实例：
 *   - strict_mode=true 时抛 DiscardedRegistrarAttributesException；
 *   - 否则记录 Log::warning。
 * 正确写法：`Route::group(['tier' => 'x'], ...)` 或 `Route::tier('x')->group(...)`。
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

    /**
     * 本实例持有的属性是否已被消费（用于注册 group 或路由）。
     * 未消费即销毁 = 属性被静默丢弃（典型场景：group(...) 返回后追加 ->tier()）。
     */
    private bool $attributesConsumed = false;

    /**
     * 重写属性设置方法，拦截 tier 属性并验证其值必须在 levels 配置中存在。
     *
     * 覆盖场景：Route::tier('x') 链式调用。
     */
    public function attribute($key, $value): static
    {
        if ($key === 'tier' && is_string($value) && $value !== '') {
            $levels = config('forge.levels', []);
            if (!isset($levels[$value])) {
                throw new UnknownLevelException(
                    'Cannot set tier [' . $value . ']: not defined in levels config. '
                    . 'Available levels: ' . implode(', ', array_keys($levels)),
                );
            }
        }

        return parent::attribute($key, $value);
    }

    /**
     * 注册路由组：属性在此处被消费（写入 groupStack），标记后委托父类。
     */
    public function group($callback): ForgeRouteRegistrar
    {
        $this->attributesConsumed = true;

        return parent::group($callback);
    }

    /**
     * 注册单条路由（get/post/resource/redirect/view 等均汇聚于此）：属性在此处被消费。
     */
    public function registerRoute($method, $uri, $action = null): Route
    {
        $this->attributesConsumed = true;

        return parent::registerRoute($method, $uri, $action);
    }

    /**
     * 销毁时检测：持有属性却从未注册 group/路由 => 属性被丢弃。
     * 典型成因：`Route::group(...)->tier('x')` —— group() 返回时组已注册完毕、
     * 组属性已出栈，尾部链式属性挂在新 Registrar 上无任何消费方。
     *
     * strict_mode=true 时抛异常，否则记录警告日志。
     */
    public function __destruct()
    {
        if ($this->attributesConsumed || $this->attributes === []) {
            return;
        }

        $message = 'RouteForge: ForgeRouteRegistrar discarded with unused attributes ['
            . implode(', ', array_keys($this->attributes))
            . ']. Attributes chained AFTER Route::group(...) do not apply to that group. '
            . "Use Route::group(['tier' => ...], ...) or Route::tier(...)->group(...) instead.";

        $strictMode = false;
        try {
            $strictMode = (bool) config('forge.strict_mode', false);
        } catch (Throwable) {
            // 应用销毁阶段容器可能已不可用
        }

        if ($strictMode) {
            throw new DiscardedRegistrarAttributesException($message);
        }

        try {
            Log::warning($message);
        } catch (Throwable) {
            // 应用销毁阶段日志组件可能已不可用，静默忽略
        }
    }
}
