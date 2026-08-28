<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use Illuminate\Routing\ResourceRegistrar;

/**
 * Forge 扩展的 ResourceRegistrar：让资源路由（resource / apiResource / singleton）
 * 支持 tier 选项，链式 `Route::resource(...)->tier('x')` 对组内全部路由生效。
 *
 * 实现方式：覆盖 getResourceAction()，把 options 中的 tier 写入路由 action 数组
 * （与 ->tier() 宏写入的位置一致，由 TierResolver 统一解析）。
 *
 * 配套组件（ForgeServiceProvider）：
 *   1. 容器绑定 ResourceRegistrar::class → ForgeResourceRegistrar（Router::resource()
 *      优先从容器解析 registrar，见 Illuminate\Routing\Router::resource()）；
 *   2. 向 PendingResourceRegistration / PendingSingletonResourceRegistration 注册
 *      tier() 宏（把 tier 存入 protected $options，注册时随 options 流入本类）。
 *
 * 优先级语义（SPEC §3.1.4）：资源级 tier 属于「显式标注」，高于 Route::group 的
 * tier 透传——ForgeRouter::mergeGroupAttributesIntoRoute 对已存在 action['tier'] 的
 * 路由不再覆盖。
 */
class ForgeResourceRegistrar extends ResourceRegistrar
{
    /**
     * 组装资源路由 action，追加 forge 扩展的 tier 字段。
     *
     * 签名说明：父类方法无参数类型提示，子类保持一致（PHP 重写规则）。
     */
    protected function getResourceAction($resource, $controller, $method, $options) // phpcs:ignore
    {
        $action = parent::getResourceAction($resource, $controller, $method, $options);

        if (isset($options['tier']) && is_string($options['tier']) && $options['tier'] !== '') {
            $action['tier'] = $options['tier'];
        }

        return $action;
    }
}
