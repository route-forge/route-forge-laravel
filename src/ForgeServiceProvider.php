<?php

declare(strict_types=1);

namespace RouteForge\Laravel;

use Closure;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Routing\Contracts\Router as RouterContract;
use Illuminate\Routing\PendingResourceRegistration;
use Illuminate\Routing\PendingSingletonResourceRegistration;
use Illuminate\Routing\ResourceRegistrar;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router as BaseRouter;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\ServiceProvider;
use RouteForge\Laravel\Cache\RouteCache;
use RouteForge\Laravel\Console\RouteForgeClearCommand;
use RouteForge\Laravel\Console\RouteForgeListCommand;
use RouteForge\Laravel\Console\RouteForgeTypesCommand;
use RouteForge\Laravel\Exceptions\UnknownLevelException;
use RouteForge\Laravel\Http\ForgeManagerController;
use RouteForge\Laravel\Http\Middleware\ManagerAllowedIps;
use RouteForge\Laravel\Http\RouteMetadataController;

/**
 * Route Forge ServiceProvider。
 *
 * 负责（对应 .docs/SPEC.md §3）：
 *   1. 注册 `->tier()` 宏到 Illuminate\Routing\Route（§3.1.1）
 *   2. 把 'router' 单例重绑为 ForgeRouter，让 Route::group(['tier'=>...]) 自动透传
 *      tier 到组内每条路由的 action（§3.1.3, §3.1.4）
 *   3. 绑定 RouteCache / TierResolver / RouteRepository 依赖（§3.1.5）
 *   4. 注册元信息查询端点 `GET /_forge/routes/{level}`（§3.1.5）
 *   5. 发布 config/forge.php
 *   6. 监听 route:clear 命令，自动连带清除 Route Forge 缓存
 *
 * 重绑时机说明：
 *   - register() 在所有 ServiceProvider 的 boot() 之前执行；
 *   - RoutingServiceProvider 的 'router' 是 lazy singleton（首次解析才实例化）；
 *   - RouteServiceProvider 通常在 boot() 阶段才解析 router 注册路由，
 *     因此我们在 register() 阶段重新绑定 'router'，可让首解析时拿到 ForgeRouter。
 *
 * ⚠ HTTP 流程下的关键坑（Laravel 11+ 骨架）：
 *   public/index.php → Application::handleRequest() → make(HttpKernel)，
 *   而 Http\Kernel::__construct(Application $app, Router $router) 在 bootstrap
 *   （RegisterProviders）之前就会解析并持有原生 Router 实例。等我们的
 *   register() 重绑 'router' 时，容器绑定虽已替换，Kernel 手里攥着的仍是旧
 *   实例：路由经 Route:: 门面注册进 ForgeRouter，分发却走旧 Router（空集合）
 *   → 所有 URL 404。因此 rebindRouter() 必须检测此情形，让 ForgeRouter 与
 *   已被捕获的旧 Router 共享同一个 RouteCollection（见 rebindRouter 实现）。
 *   Console Kernel 构造函数不注入 Router，Artisan 流程无此问题。
 */
class ForgeServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerTierMacro();
        $this->registerMetadataEndpoint();
        $this->registerViewNamespace();
        $this->registerManagerRoutes();
        $this->publishConfig();
        $this->listenRouteClear();

        // 注册 Artisan 命令（SPEC §3.2）
        $this->commands([
            RouteForgeListCommand::class,
            RouteForgeTypesCommand::class,
            RouteForgeClearCommand::class,
        ]);
    }

    public function register(): void
    {
        $this->rebindRouter();
        $this->registerResourceRegistrar();
        $this->registerBindings();
        $this->mergeConfigFrom(__DIR__ . '/../config/forge.php', 'forge');
    }

    /**
     * 绑定 ForgeResourceRegistrar 为容器中的 ResourceRegistrar 实现。
     *
     * Router::resource() / Router::singleton() 优先从容器解析 registrar，
     * 绑定后资源路由的注册经由 ForgeResourceRegistrar 完成，
     * 使 options 中的 tier 流入每条资源路由的 action（配套 registerTierMacro 的宏）。
     */
    protected function registerResourceRegistrar(): void
    {
        $this->app->singleton(ResourceRegistrar::class, function ($app) {
            /** @var Container $app */
            return new ForgeResourceRegistrar($app->make('router'));
        });
    }

    /**
     * 重绑 'router' 单例为 ForgeRouter，覆盖 Illuminate\Routing\Router。
     *
     * 若重绑前 'router' 已被解析（HTTP 流程下 Http\Kernel 构造函数先于
     * RegisterProviders 解析并捕获了原生 Router），则额外让新 ForgeRouter
     * 与旧实例共享同一个 RouteCollection：
     *   - 后续经 Route:: 门面注册的路由（走 ForgeRouter）进入共享集合；
     *   - Kernel 分发时用其持有的旧 Router，命中的仍是同一个集合；
     *   - group(['tier']) 透传由 ForgeRouter 的 updateGroupStack /
     *     mergeGroupAttributesIntoRoute 覆盖完成，行为不受影响。
     */
    protected function rebindRouter(): void
    {
        // 重绑前先捕获可能已解析的原生 Router 实例（Kernel 可能已持有它）
        $previous = $this->app->resolved('router') ? $this->app->make('router') : null;

        $this->app->singleton('router', function ($app) {
            /** @var Container $app */
            $events = $app->make(Dispatcher::class);
            $router = new ForgeRouter($events, $app);

            // 同步 Router 与 routes collection 的事件订阅
            // （与原生 RoutingServiceProvider 行为一致）
            if (method_exists($router, 'setEventsDispatcher')) {
                $router->setEventsDispatcher($events);
            }

            return $router;
        });

        // 同时绑定 Router 契约别名，避免某些包通过 alias 解析时绕过 ForgeRouter
        $this->app->alias('router', RouterContract::class);

        // 清除 Route 门面缓存的 root 实例。Facade 不感知容器 rebind：
        // 若 'router' 在重绑前已被门面解析过（如 Laravel 12 的
        // FilesystemServiceProvider 在 booted 阶段经 Route::get 注册 storage 路由），
        // 门面将一直持有旧 Router 实例，后续 Route:: 调用写入旧实例的集合，
        // 造成「注册」与「分发」操作两张路由表。清除后下次门面调用会重新
        // 解析容器，拿到 ForgeRouter。
        RouteFacade::clearResolvedInstance('router');

        if ($previous instanceof BaseRouter && ! $previous instanceof ForgeRouter) {
            // Kernel（或其他早于本 provider 的代码）已持有原生 Router 引用：
            // 替换引用不可能，只能让 ForgeRouter 共享其 routes collection，
            // 保证「门面注册」与「Kernel 分发」操作同一个路由集合，避免全量 404。
            $this->app->make('router')->setRoutes($previous->getRoutes());
        }
    }

    /**
     * 绑定 RouteForge 后端服务的核心依赖（§3.1.5）。
     *
     * RouteMetadataController 通过构造函数注入 RouteRepository；
     * 容器自动解析时，需要预先绑定 RouteCache / TierResolver / RouteRepository
     * 这三个非自动可解析的构造参数（特别是 array 类型）。
     */
    protected function registerBindings(): void
    {
        // RouteCache：按 forge.cache_driver 选择 store；null 表示用默认 cache.store
        // 开发模式（app.debug=true）下跳过所有缓存读写，路由变更即时生效
        // TTL 由 forge.cache_ttl 统一控制所有层级与摘要端点
        $this->app->singleton(RouteCache::class, function ($app) {
            /** @var Container $app */
            $driver = $app->make('config')->get('forge.cache_driver');
            $store = $driver === null
                ? $app->make(CacheRepository::class)
                : $app->make('cache')->store($driver);
            $debugMode = (bool) $app->make('config')->get('app.debug', false);
            $ttl = $app->make('config')->get('forge.cache_ttl');
            return new RouteCache($store, $debugMode, $ttl !== null ? (int) $ttl : null);
        });

        // TierResolver：从 forge 配置组装
        $this->app->singleton(TierResolver::class, function ($app) {
            /** @var Container $app */
            $classifier = $app->make('config')->get('forge.classifier');
            // classifier 契约类型是 callable|null（SPEC §5）：Closure、函数名字符串、
            // [Class,'method'] 数组、可调用对象均合法。统一归一为 Closure 传入，
            // 避免非 Closure callable 被静默丢弃（TierResolver 参数类型为 ?Closure）。
            $classifier = is_callable($classifier) ? Closure::fromCallable($classifier) : null;
            return new TierResolver(
                levelsConfig: $app->make('config')->get('forge.levels', []),
                classifier: $classifier,
                strictMode: (bool) $app->make('config')->get('forge.strict_mode', false),
            );
        });

        // RouteRepository：组合 router + tierResolver + cache + levelsConfig + aliasesConfig
        $this->app->singleton(RouteRepository::class, function ($app) {
            /** @var Container $app */
            return new RouteRepository(
                router: $app->make('router'),
                tierResolver: $app->make(TierResolver::class),
                cache: $app->make(RouteCache::class),
                levelsConfig: $app->make('config')->get('forge.levels', []),
                aliasesConfig: $app->make('config')->get('forge.aliases', []),
            );
        });
    }

    /**
     * 注册 `->tier()` 宏到 Illuminate\Routing\Route。
     * 仅向 action 数组写入一个 `tier` 字段，零侵入。
     *
     * 同时向 PendingResourceRegistration / PendingSingletonResourceRegistration
     * 注册 tier() 宏，让 `Route::resource(...)->tier('x')` 链式写法生效
     * （SPEC §3.1.1：链式调用对资源路由同样生效）。tier 经 options 流入
     * ForgeResourceRegistrar，注册时写入每条资源路由的 action。
     */
    protected function registerTierMacro(): void
    {
        /** @var Route $route */
        Route::macro('tier', function (string $tier): Route {
            // 与其它所有 tier 入口（链式 Registrar / group 数组 / 资源路由）一致：
            // 定义时立即校验层级合法性，fail-fast 而非延迟到端点/命令解析时抛错
            $levels = config('forge.levels', []);
            if (!isset($levels[$tier])) {
                throw new UnknownLevelException(
                    'Cannot set tier [' . $tier . ']: not defined in levels config. '
                    . 'Available levels: ' . implode(', ', array_keys($levels)),
                );
            }

            $action = $this->getAction();
            $action['tier'] = $tier;
            $this->setAction($action);
            return $this;
        });

        $resourceTier = function (string $tier) {
            $levels = config('forge.levels', []);
            if (!isset($levels[$tier])) {
                throw new UnknownLevelException(
                    'Cannot set tier [' . $tier . ']: not defined in levels config. '
                    . 'Available levels: ' . implode(', ', array_keys($levels)),
                );
            }
            /** @phpstan-ignore-next-line 宏经 Macroable 绑定 $this，可访问 protected $options */
            $this->options['tier'] = $tier;
            return $this;
        };

        PendingResourceRegistration::macro('tier', $resourceTier);
        PendingSingletonResourceRegistration::macro('tier', $resourceTier);

        // `->forgeAlias()` 宏（SPEC §3.1.7）：为当前路由声明一个或多个旧名别名，
        // 使前端在路由改名后继续使用旧名（别名条目由 RouteRepository 注入元信息）。
        // 与 tier 宏同样零侵入：仅向 action 数组写入 forge_aliases 字段
        //（写 action 而非 defaults——defaults 会泄漏进 parameter_defaults 元信息）。
        // 声明期只做基本校验；目标存在性/撞车等跨路由校验延迟到 AliasResolver
        // 扫描期（声明时路由表尚未注册完成，无法可靠校验）。
        Route::macro('forgeAlias', function (string ...$aliases): Route {
            if (empty($aliases)) {
                throw new \InvalidArgumentException(
                    'forgeAlias() requires at least one alias name.',
                );
            }
            foreach ($aliases as $alias) {
                if (trim($alias) === '') {
                    throw new \InvalidArgumentException(
                        'Alias name passed to forgeAlias() cannot be empty.',
                    );
                }
            }

            $action   = $this->getAction();
            $existing = $action['forge_aliases'] ?? [];
            if (!is_array($existing)) {
                $existing = [];
            }
            $action['forge_aliases'] = array_values(array_unique(array_merge($existing, $aliases)));
            $this->setAction($action);
            return $this;
        });
    }

    /**
     * 注册元信息查询端点 GET /{endpoint_prefix}/{level} 与摘要端点 GET /{endpoint_prefix}。
     *
     * 方案 B：按层级注册独立路由，每个层级可配置自己的 endpoint_middleware。
     * 未配置 endpoint_middleware 的层级不附加中间件。
     * 摘要端点受顶层 endpoint_middleware 配置保护。
     *
     * prefix 规范化：确保前导 /、去除尾部 /，避免双斜杠（默认配置为 '/_forge/routes'
     * 已带前导 /，不可再额外拼接）。
     */
    protected function registerMetadataEndpoint(): void
    {
        $prefix = (string) config('forge.endpoint_prefix', '/_forge/routes');
        // 规范化：确保前导 /，去除尾部 /，避免双斜杠
        $prefix = '/' . ltrim(rtrim($prefix, '/'), '/');

        $levels = config('forge.levels', []);
        $router = $this->app['router'];

        // 按层级注册独立路由，每个层级可配置自己的 endpoint_middleware
        foreach ($levels as $levelName => $levelConfig) {
            $route = $router->get(
                "{$prefix}/{$levelName}",
                [RouteMetadataController::class, 'show']
            )->defaults('level', $levelName);

            $endpointMiddleware = $levelConfig['endpoint_middleware'] ?? [];
            if (count($endpointMiddleware) > 0) {
                $route->middleware($endpointMiddleware);
            }
        }

        // 兜底路由：匹配不在 levels 中的层级名，返回 404（RF_BE_002）
        $router->get(
            "{$prefix}/{level}",
            [RouteMetadataController::class, 'show']
        )->where('level', '.*')->name('forge.routes.show');

        // 摘要端点（SPEC §3.1.6）：GET /{prefix}
        $summaryRoute = $router->get(
            $prefix,
            [RouteMetadataController::class, 'index']
        )->name('forge.routes.index');

        $summaryMiddleware = config('forge.endpoint_middleware', []);
        if (is_array($summaryMiddleware) && count($summaryMiddleware) > 0) {
            $summaryRoute->middleware($summaryMiddleware);
        }
    }

    /**
     * 注册 Blade 视图命名空间 forge:: → resources/views/。
     *
     * 管理器页面使用 view('forge::manager') 渲染，
     * 通过命名空间隔离避免与宿主项目的视图冲突。
     */
    protected function registerViewNamespace(): void
    {
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'forge');
    }

    /**
     * 注册管理器页面路由（仅开发环境）。
     *
     * 管理器页面提供可视化路由管理面板，仅在 APP_DEBUG=true 时可用：
     *   - GET  /_forge/manager          → HTML 面板
     *   - GET  /_forge/manager/api/routes → 所有路由 JSON
     *   - GET  /_forge/manager/api/config → 当前配置 JSON
     *   - PUT  /_forge/manager/api/config → 更新配置文件
     *
     * 访问控制两层：
     *   1. 非开发环境（APP_DEBUG=false）不注册任何路由，确保零泄露风险；
     *   2. 开发环境内再经 ManagerAllowedIps 中间件按
     *      forge.manager_allowed_ips 配置做 IP 白名单限制
     *      （默认仅本机回环地址可访问，见 config/forge.php）。
     */
    protected function registerManagerRoutes(): void
    {
        if (!config('app.debug', false)) {
            return;
        }

        $router = $this->app['router'];
        $prefix = '/_forge/manager';

        $routes = [
            $router->get($prefix, [ForgeManagerController::class, 'index'])
                   ->name('forge.manager.index'),
            $router->get("{$prefix}/api/routes", [ForgeManagerController::class, 'routes'])
                   ->name('forge.manager.api.routes'),
            $router->get("{$prefix}/api/config", [ForgeManagerController::class, 'config'])
                   ->name('forge.manager.api.config'),
            $router->put("{$prefix}/api/config", [ForgeManagerController::class, 'updateConfig'])
                   ->name('forge.manager.api.config.update'),
        ];

        // IP 白名单守卫：来源不在允许列表内返回 403
        foreach ($routes as $route) {
            $route->middleware(ManagerAllowedIps::class);
        }
    }

    /**
     * 发布 config/forge.php 到宿主项目
     */
    protected function publishConfig(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/forge.php' => $this->app->configPath('forge.php'),
            ], 'forge-config');
        }
    }

    /**
     * 监听 Laravel 内置 route:clear 命令，执行时自动连带清除 Route Forge 缓存。
     *
     * 开发者运行 php artisan route:clear 时，通常意味着路由定义发生了变更，
     * Route Forge 的路由元信息缓存也应一并失效，避免端点返回过期数据。
     *
     * 实现说明：
     *   Laravel Kernel 的 handle() 方法会调用 rerouteSymfonyCommandEvents()
     *   将 Symfony Console 事件桥接为 Laravel 事件（CommandStarting 等），
     *   但 call() 方法（用于 Kernel::call / $this->artisan）不会自动调用。
     *   因此这里主动调用以确保事件桥接在任何调用方式下都生效。
     */
    protected function listenRouteClear(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        // 确保 Symfony → Laravel 事件桥接已启用
        $kernel = $this->app->make(Kernel::class);
        if (method_exists($kernel, 'rerouteSymfonyCommandEvents')) {
            $kernel->rerouteSymfonyCommandEvents();
        }

        $this->app['events']->listen(CommandStarting::class, function (CommandStarting $event) {
            if ($event->command === 'route:clear') {
                $this->app->make(RouteCache::class)->clear();
            }
        });
    }
}
