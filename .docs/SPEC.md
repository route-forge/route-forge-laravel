# Route Forge — 功能规格说明书

> 对外功能承诺，所有实现都应对照此文档验证。

## 1. 产品概述

**Route Forge for Laravel** 是 Laravel 命名路由的全链路后端解决方案，提供：

- 路由分级（tier）分配：三种互相兼容的分配方式（显式 `->tier()` 宏、`Route::group` 透传、配置文件批量匹配）
- 五级优先级：显式标注 > 分组继承 > 自定义回调 > 配置匹配 > unassigned 兜底
- 元信息端点：按层级返回路由元信息（名称 + URI + method + 参数），供前端按需懒加载
- 摘要端点：返回所有层级概览与全局配置，供前端初始化自动发现
- 统一缓存：所有层级端点与摘要端点共享同一 TTL 配置，支持多种缓存驱动
- Artisan 命令：查看层级分配结果、生成 TS 类型声明文件

## 2. 目标用户

| 画像                | 典型场景                                              |
|---------------------|-------------------------------------------------------|
| Laravel 全栈开发者  | Laravel + Vue/React SPA 项目，想让前端调用 API 更优雅 |
| 大型 SPA 项目维护者 | 接口数 > 100，首屏性能敏感                            |
| 多角色系统开发者    | 有 admin/user/guest 等多种权限级别                    |
| TypeScript 重度用户 | 要求前后端全链路类型安全，需要后端生成 TS 类型声明    |

## 3. 后端功能

### 3.1 核心能力

Route Forge 提供三种互相兼容的路由层级分配方式，任选其一或组合使用。三层级名（如 `admin/manage/client`、`public/user/admin`
）完全由项目自定义，包不预设固定层级（见 DESIGN.md §6.2）。

#### 3.1.1 定义路由时显式标记（`->tier()` 宏）

通过宏 `tier()` 在定义路由时显式标记所属层级，链式调用对资源路由同样生效：

```php
Route::post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login')
    ->tier('public');

Route::resource('users', UserController::class)
    ->tier('manage');

Route::apiResource('posts', PostController::class)
    ->tier('manage');

Route::singleton('profile', ProfileController::class)
    ->tier('client');
```

宏通过 ServiceProvider 注册到 `Illuminate\Routing\Route`，仅向 action 数组写入一个 `tier` 字段，零侵入：

```php
Route::macro('tier', function (string $tier) {
    $this->action['tier'] = $tier;
    return $this;
});
```

资源路由（`resource` / `apiResource` / `singleton` / `apiSingleton`）返回的 Pending 注册对象同样注册了 `tier()` 宏：tier 暂存于资源 options，注册时经 `ForgeResourceRegistrar` 写入每条资源路由的 action，语义与单条路由的显式 `->tier()` 完全一致（包括「显式标注优先于 group 透传」，见 §3.1.4）。非法层级名在链式调用时立即抛 `UnknownLevelException`。

#### 3.1.2 配置文件按规则批量分配

`config/forge.php` 中按 `match` 规则把路由批量匹配到对应层级，无需逐条标注：

```php
// config/forge.php
return [
    'levels' => [
        'public' => [
            'description' => '公共接口（无需登录）',
            'match' => [
                'prefix'     => ['auth', 'public'],
                'middleware' => [],
            ],
            'load'  => 'eager',
        ],
        'client' => [
            'description' => '客户端用户接口',
            'match' => [
                'prefix'     => ['client'],
                'middleware' => ['auth'],
            ],
            'load'  => 'lazy',
        ],
        'manage' => [
            'description' => '运营管理接口',
            'match' => [
                'prefix'     => ['manage'],
                'middleware' => ['auth', 'manage'],
            ],
            'load'  => 'lazy',
        ],
        'admin' => [
            'description' => '系统管理接口',
            'match' => [
                'prefix'     => ['admin'],
                'middleware' => ['auth', 'admin'],
                'middleware_match' => 'all',  // 要求同时包含 auth 和 admin
            ],
            'load'  => 'lazy',
            'endpoint_middleware' => ['auth', 'admin'],  // 访问 /_forge/routes/admin 需要 auth + admin
        ],
    ],

    'endpoint_prefix' => '/_forge/routes',  // 路由元信息对外端点前缀
    'endpoint_middleware' => [],              // 摘要端点中间件，null 或空数组 = 不限制
    'cache_ttl'       => 3600,              // 统一缓存 TTL（秒），null=不缓存
    'cache_driver'    => null,              // null=使用默认缓存驱动
    'strict_mode'     => false,             // 严格模式：未命中层级即抛异常
    'classifier'      => null,              // 自定义分类回调，签名 fn(Route $r): ?string
];
```

匹配规则：

- `prefix`：路由 URI 命中任意一个前缀即归入此层级（支持多个）。
- `middleware`：路由中间件集合按 `middleware_match` 规则匹配（详见下方「中间件匹配模式」）。
- 显式 `->tier()` 标记优先级最高，覆盖配置匹配结果（见 3.1.4）。
- 多个层级同时命中时，按 `levels` 数组定义顺序取最后一个（后定义覆盖前定义，与 `Route::group`
  内层覆盖外层的语义一致）。因此把新层级**追加到数组末尾**即可生效，无需改动已有层级；但反过来说，
  若某条新规则与已有层级冲突命中且你希望**旧层级继续优先**，则必须把新层级放到旧层级**之前**——
  顺序即优先级，越靠后越优先。
- 全部未命中：`strict_mode=true` 抛 `RouteTierNotAssignedException`；`strict_mode=false` 时归入 `unassigned` 特殊层级（唯一的兜底机制，见 §3.1.4）。
- `classifier` 回调优先级介于「显式 `->tier()` / `Route::group` tier」与「配置 match」之间（完整五级优先级见
  §3.1.4），用于实现复杂自定义分类逻辑（如基于 Controller 命名空间归类）。 **注意**：由于显式 `->tier()`
  会直接返回不继续匹配，classifier 实际无法覆盖显式 tier，仅对未显式标注的路由生效。返回的层级名必须在
  `levels` 配置中存在，否则抛 `UnknownClassifierTierException`（无论 `strict_mode` 是否开启）。

##### 中间件匹配模式（`middleware_match`）

每个层级可在 match 中配置 `middleware_match`，控制 middleware 数组的匹配逻辑。不配置时默认 `'any'`。

简单模式：

| 值            | 含义                                                          |
|---------------|---------------------------------------------------------------|
| 'any'（默认） | 路由中间件集合包含 middleware 数组中任意一项即命中（OR 逻辑） |
| 'all'         | 路由中间件集合包含 middleware 数组中全部项才命中（AND 逻辑）  |

高级模式（数组 DNF 结构）：

以 `middleware` 数组索引（从 0 开始）为操作数，用嵌套数组表达布尔逻辑——内层数组为 AND，外层数组为 OR（析取范式 / DNF）：

| 配置示例      | 等价逻辑                                           |
|---------------|----------------------------------------------------|
| [[0, 1]]      | middleware[0] AND middleware[1]                    |
| [[0], [1]]    | middleware[0] OR middleware[1]                     |
| [[0, 1], [2]] | (middleware[0] AND middleware[1]) OR middleware[2] |
| [[0], [1, 2]] | middleware[0] OR (middleware[1] AND middleware[2]) |

```php
// 示例 1：admin 层级要求同时有 auth 和 admin 中间件（AND）
'admin' => [
    'match' => [
        'prefix'     => ['admin'],
        'middleware' => ['auth', 'admin'],
        'middleware_match' => 'all',
    ],
    'load' => 'lazy',
],

// 示例 2：复杂条件 (有 auth 且有 admin) 或 (有 super_admin)
'admin' => [
    'match' => [
        'prefix'     => ['admin'],
        'middleware' => ['auth', 'admin', 'super_admin'],
        'middleware_match' => [[0, 1], [2]],
    ],
    'load' => 'lazy',
],
```

> 选择 DNF 数组而非字符串表达式的原因：PHP 原生数组无需额外解析器，写错时 PHP 直接报类型错误，不会静默失败。DNF
> 可表达所有布尔组合，覆盖实际使用场景。

#### 3.1.3 路由分组分配层级（`Route::group` 透传）

在 `Route::group` 上支持 `tier` 选项，整组路由继承层级，避免重复标注。命名空间、中间件、前缀等 Laravel 原生 group 选项继续正常工作：

```php
Route::group([
    'prefix'     => 'admin',
    'middleware' => ['auth', 'admin'],
    'tier'       => 'admin',   // ← 新增选项
], function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
    Route::post('/users', [AdminUserController::class, 'store'])
         ->name('admin.users.store');
    // 组内所有路由自动归属 admin 层级
});
```

嵌套 group 行为（与 Laravel 中 `middleware` 合并策略一致）：

```php
Route::group(['tier' => 'admin'], function () {
    Route::get('/a', ...);              // admin

    Route::group(['tier' => 'manage'], function () {
        Route::get('/b', ...);          // manage（内层覆盖外层）
    });
});
```

实现方式：register 阶段把容器中的 `router` 单例重绑为 `ForgeRouter`（继承 Laravel Router，覆盖
group 属性合并逻辑），`tier` 透传到组内每条路由的 action，等价于自动给组内每条路由调用
`->tier()`。分组标记与单条显式 `->tier()` 相比，单条优先级更高。

> ⚠️ **尾部链式写法不受支持**：`Route::group([...], fn)->tier('x')` 不会将 `tier` 应用于该组。
> Laravel 的 `group()` 返回时组内路由已注册完毕、组属性已出栈，其后链式创建的 Registrar 属性无消费方，
> 会被静默丢弃。Forge 对此场景做运行时检测：持有属性却从未注册 group/路由的 Registrar 被销毁时，
> 向 Laravel 日志写入一条 `warning`（含被丢弃的属性名与正确写法提示）。
> 组级 `tier` 只支持两种写法：数组选项 `Route::group(['tier' => ...], fn)` 与前置链式 `Route::tier(...)->group(fn)`。

#### 3.1.4 层级分配优先级

当多种分配方式并存时，按以下优先级决定一条路由的最终层级（高优先级覆盖低优先级）：

1. **显式** **`->tier()`** **调用**（最高）：直接返回，不继续后续匹配
2. **`Route::group`** **的** **`tier`** **选项**（继承自最近一层 group，内层覆盖外层）
3. **`classifier`** **自定义回调**返回非 null 值
4. **配置文件** **`match`** **规则**匹配（受 `middleware_match` 控制）
5. **兜底**：归入 `unassigned` 特殊层级（经层级端点获取，见 §3.1.5 / §3.1.6）

> 优先级设计意图：显式标注胜过隐式分组，分组胜过全局规则，全局规则胜过兜底。
> 未标记路由仍可被前端调用，只需通过摘要端点中的 `unassigned` 特殊层级发现，
> 并经其层级端点（`GET /{endpoint_prefix}/unassigned`）获取明细。
> 兜底机制只有 unassigned 一种（早期版本的 `fallback_level` 配置因与之语义重复已移除）。
>
> ⚠️ 安全考量：未标记路由会通过 `unassigned` 层级端点暴露路由名和 URI 模板。生产环境建议配合 `strict_mode=true`
> 或显式标记所有路由，避免信息泄露。

#### 3.1.5 层级元信息查询端点

包在 `endpoint_prefix`（默认 `/_forge/routes`）下注册端点，按层级返回路由元信息（名称 + URI + method + 参数定义），供前端按需懒加载：

```
GET /_forge/routes/{level}   # 返回该层级下所有命名路由的元信息
```

`{level}` 支持特殊值 `unassigned`：返回所有未命中任何层级的命名路由，响应结构与已定义层级完全一致。

返回示例：

```json
{
  "level": "admin",
  "routes": {
    "admin.users.index": {
      "uri": "admin/users",
      "methods": [
        "GET",
        "HEAD"
      ],
      "parameters": [],
      "parameter_defaults": {}
    },
    "admin.users.show": {
      "uri": "admin/users/{user}",
      "methods": [
        "GET",
        "HEAD"
      ],
      "parameters": [
        "user"
      ],
      "parameter_defaults": {}
    },
    "admin.posts.index": {
      "uri": "admin/posts/{page?}",
      "methods": [
        "GET",
        "HEAD"
      ],
      "parameters": [
        "page"
      ],
      "parameter_defaults": {
        "page": "1"
      }
    }
  }
}
```

缓存：所有层级端点与摘要端点统一使用 `cache_ttl` 配置项控制 TTL（单位秒，null 不缓存，0 永久缓存）。
> ⚠ cache_ttl: 0 遵循 Laravel Cache TTL 惯例（永久缓存），非 HTTP Cache-Control: max-age=0 含义。Route Forge 缓存仅通过包内部管理（Cache
> facade / 配置的 cache_driver），不使用 HTTP 响应头。

##### 端点中间件保护（`endpoint_middleware`）

每个层级可配置 `endpoint_middleware` 字段，控制访问该层级元信息端点时要求的中间件。未配置或为空时不限制访问：

```php
'admin' => [
    // ... match / load 配置 ...
    'endpoint_middleware' => ['auth', 'admin'],  // GET /_forge/routes/admin 需要 auth + admin 中间件
],
'public' => [
    // ... match / load 配置 ...
    // 未配置 endpoint_middleware → GET /_forge/routes/public 无中间件限制
],
```

摘要端点（`GET /_forge/routes`）受顶层 `endpoint_middleware` 配置保护（见 §5）。

实现方式：按层级注册独立路由（方案 B），每个层级路由直接挂载对应的中间件。未配置 `endpoint_middleware` 的层级不附加任何中间件，保持原有行为。

#### 3.1.6 层级摘要端点

包同时注册一个摘要端点，返回所有层级的概览信息与后端全局配置，供前端初始化时自动发现层级、读取后端配置：

```text
GET /_forge/routes   # 返回所有层级摘要 + 全局配置
```

返回示例：

```json
{
  "schemeVersion": 1,
  "levels": {
    "public": {
      "description": "公共接口（无需登录）",
      "load": "eager",
      "route_count": 12,
      "route": {
        "uri": "/_forge/routes/public",
        "methods": ["GET", "HEAD"]
      }
    },
    "client": {
      "description": "客户端用户接口",
      "load": "lazy",
      "route_count": 45,
      "route": {
        "uri": "/_forge/routes/client",
        "methods": ["GET", "HEAD"]
      }
    },
    "manage": {
      "description": "运营管理接口",
      "load": "lazy",
      "route_count": 38,
      "route": {
        "uri": "/_forge/routes/manage",
        "methods": ["GET", "HEAD"]
      }
    },
    "admin": {
      "description": "系统管理接口",
      "load": "lazy",
      "route_count": 27,
      "route": {
        "uri": "/_forge/routes/admin",
        "methods": ["GET", "HEAD"]
      }
    },
    "unassigned": {
      "description": "未命中任何层级的路由",
      "load": "lazy",
      "route_count": 1,
      "route": {
        "uri": "/_forge/routes/unassigned",
        "methods": ["GET", "HEAD"]
      }
    }
  },
  "config": {
    "strict_mode": false,
    "endpoint_prefix": "/_forge/routes",
    "url_prefix": "https://api.example.com/v1",
    "cache_ttl": 3600
  }
}
```

字段说明：

+ `schemeVersion`：摘要端点响应格式版本号，默认 `1`。后续迭代引入不兼容的格式变更时递增，前端应据此字段识别格式版本并做兼容处理。
+ `levels`：各层级摘要。`description` 层级描述、`load` 加载策略（eager/lazy）、`route_count` 该层级路由数量、`route` 该层级元信息端点的请求信息（`uri` + `methods`），前端可据此直接构造请求获取该层级的全量路由数据。
  - `unassigned`：特殊层级，与已定义层级结构完全一致，汇总所有未命中任何层级的命名路由。其路由明细不在摘要中内联返回，前端按 `route` 字段另行请求 `GET /{endpoint_prefix}/unassigned` 获取（见 §3.1.5）。
+ `config`：后端全局配置摘要。前端初始化时读取此字段作为最高优先级配置源（后端为权威值，覆盖前端本地配置）。当前包含
  `strict_mode`、`endpoint_prefix`、`url_prefix` 和 `cache_ttl`，后续版本可扩展。
  - `url_prefix`：应用的路由前缀，支持两种格式——完整 URL（含协议和域名，如
    `https://api.example.com/v1`）或仅路径前缀（如 `/api/v1`）。未配置时为 `null`，前端视为无前缀。

摘要端点同样受 `cache_driver` 与 `cache_ttl` 控制缓存。

#### 3.2 Artisan命令

#### `php artisan route:forge:list`

查看所有路由的层级分配结果，用于开发调试和验证配置是否正确：

```bash
# 查看所有路由的层级分配
php artisan route:forge:list
# 仅查看指定层级
php artisan route:forge:list --level=admin
# JSON 格式输出（便于脚本处理）
php artisan route:forge:list --json
# 仅显示未分配层级的路由
php artisan route:forge:list --unassigned
```

输出示例：

| Name                | Level      | Methods   | URI                |
|---------------------|------------|-----------|--------------------|
| auth.login          | public     | POST      | auth/login         |
| admin.users.index   | admin      | GET\|HEAD | admin/users        |
| admin.users.show    | admin      | GET\|HEAD | admin/users/{user} |
| client.orders.store | client     | POST      | client/orders      |
| debug.info          | unassigned | GET\|HEAD | _debug/info        |

行为说明：

- 数据源：直接从 Laravel 路由注册表（`Route::getRoutes()`）读取， **不需要启动 HTTP 服务**，离线可用。
- 层级分配逻辑与运行时完全一致（遵循 §3.1.4 五级优先级）。
- `--level` 过滤时，若层级名不存在则提示可用层级列表；`unassigned` 特殊层级也可作为 `--level` 过滤值。
- `--unassigned` 仅显示未命中任何层级的路由，与 `--level=unassigned` 等价。
- 未分配路由在 table 输出中以 `unassigned` 显示（与 JSON 输出及特殊层级名保持一致）。

##### `--json` 输出结构

`--json` 输出结构化对象（便于脚本消费），而非纯数组：

```json
{
  "levels": ["public", "client", "manage", "admin", "unassigned"],
  "filter": null,
  "count": 5,
  "routes": [
    {
      "name": "auth.login",
      "level": "public",
      "methods": ["POST"],
      "uri": "auth/login"
    },
    {
      "name": "debug.info",
      "level": "unassigned",
      "methods": ["GET"],
      "uri": "_debug/info"
    }
  ]
}
```

字段说明：

- `levels`：当前可用层级列表（始终含 `unassigned` 特殊层级）。
- `filter`：当前过滤条件（`--level` / `--unassigned`），无过滤时为 `null`。
- `count`：匹配路由总数。
- `routes`：路由条目数组，每条含 `name`、`level`（未分配为 `"unassigned"`）、`methods`、`uri`。

> 设计意图：开发阶段最常被问到的问题是"我的路由到底被分到了哪个层级"。这个命令让开发者无需启动前端、无需打开浏览器，一条命令即可验证配置效果。

#### `php artisan route:forge:types`

从 Laravel 路由注册表生成 TS 类型声明文件，为前端 `forge.api()` 调用提供编译期类型安全：

```bash
# 生成所有层级的路由类型（默认输出到 stdout，便于预览和管道处理）
php artisan route:forge:types
# 仅生成指定层级
php artisan route:forge:types --level=admin
# 写入文件（跨项目写入前端目录）
php artisan route:forge:types --out=../frontend/src/types/forge-routes.d.ts
# JSON 格式输出（便于脚本或工具链二次消费）
php artisan route:forge:types --json
```

生成结果示例（d.ts）：

```ts
// AUTO-GENERATED by route:forge:types. Do not edit.
// 生成时间: 2026-08-22T12:00:00.000Z
// 端点: /_forge/routes
import { ForgeRouteMap } from "@route-forge/core";

// ─── 层级名联合类型 ───────────────────────────────────────────
export type ForgeLevel = 'public' | 'manage' | 'admin';

// ─── 各层级路由名联合类型 ─────────────────────────────────────
export type ForgeRouteName<L extends ForgeLevel> = L extends keyof ForgeRouteMap
  ? keyof ForgeRouteMap[L] & string
  : never;

// ─── 单条路由元信息（端点返回的 routes 值结构） ────────────────────
export interface ForgeRouteMeta {
  method: string;
  uri: string;
  parameters: string[];
  parameter_defaults: Record<string, unknown>;
}

// ─── 按层级 → 路由名 → 类型约束的映射 ────────────────────────
// 通过 module augmentation 增强 @route-forge/core 的 ForgeRouteMap，
// 使 useForge / useForgeApi 自动获得路由名和参数的类型推断。
// ⚠ 依赖 @route-forge/core 包，请确保前端项目已安装该依赖。
declare module '@route-forge/core' {
  interface ForgeRouteMap {
    public: {
      'auth.login': {
        method: 'POST';
        params: {};
        body: unknown;
        response: unknown;
      };
    };
    admin: {
      'admin.users.show': {
        method: 'GET';
        params: { user: string | number };
        response: unknown;
      };
      'admin.users.store': {
        method: 'POST';
        params: {};
        body: unknown;
        response: unknown;
      };
    };
    manage: {
      'manage.users.store': {
        method: 'POST';
        params: {};
        body: unknown;
        response: unknown;
      };
    };
  }
}

// ─── 本地别名（向后兼容） ──────────────────────────────────────
export type ForgeRoutes = ForgeRouteMap;

// ─── 工具类型：从 ForgeRouteMap 提取具体字段 ────────────────────

/** 提取指定层级 + 路由名的 method */
export type ForgeMethod<
  L extends ForgeLevel,
  N extends ForgeRouteName<L>,
> = L extends keyof ForgeRouteMap
  ? N extends keyof ForgeRouteMap[L] ? ForgeRouteMap[L][N]['method'] : never
  : never;

/** 提取指定层级 + 路由名的路径参数 */
export type ForgeParams<
  L extends ForgeLevel,
  N extends ForgeRouteName<L>,
> = L extends keyof ForgeRouteMap
  ? N extends keyof ForgeRouteMap[L] ? ForgeRouteMap[L][N]['params'] : never
  : never;

/** 提取指定层级 + 路由名的 body 类型（GET/DELETE 为 never） */
export type ForgeBody<
  L extends ForgeLevel,
  N extends ForgeRouteName<L>,
> = L extends keyof ForgeRouteMap
  ? N extends keyof ForgeRouteMap[L]
    ? 'body' extends keyof ForgeRouteMap[L][N]
      ? ForgeRouteMap[L][N]['body']
      : never
    : never
  : never;

/** 提取指定层级 + 路由名的 response 类型 */
export type ForgeResponse<
  L extends ForgeLevel,
  N extends ForgeRouteName<L>,
> = L extends keyof ForgeRouteMap
  ? N extends keyof ForgeRouteMap[L] ? ForgeRouteMap[L][N]['response'] : never
  : never;
```

结构说明：

- 映射通过 `declare module '@route-forge/core'` 的 **module augmentation** 增强 `ForgeRouteMap`
  接口——**二级映射**，第一级 key 是层级名（如 `public`/`admin`/`manage`），第二级 key 是路由名
  （如 `admin.users.show`）。前端 composable（useForge / useForgeApi）自动获得类型推断；
  `export type ForgeRoutes = ForgeRouteMap` 为本地别名（向后兼容）。
- 生成的 d.ts 依赖前端包 `@route-forge/core`（import 语句），前端项目需已安装。
- 每条路由包含：`method`（字符串字面量，取第一个非 HEAD 的 HTTP 方法）、`params`（路径参数对象，类型固定
  `string | number`；URL 可选参数 `{param?}` 标记为 `?`；无参数时为空对象 `{}`）、`body`（仅
  POST/PUT/PATCH 方法有此字段，默认 `unknown`）、
  `response`（响应类型，默认 `unknown`）。
- `parameter_defaults` 独立返回参数默认值，与 TS 类型的 `?` 标记无关： URL 必选参数 `{user}`
  即使设置了默认值，在 TS 中仍然是必选字段； 前端可根据 `parameter_defaults` 在构造 URL 时自动填充默认值。
- `ForgeLevel`、`ForgeRouteName<L>` 用于在前端 `forge.api(level, name, params)` 调用时提供类型约束。
- 工具类型 `ForgeMethod`、`ForgeParams`、`ForgeBody`、`ForgeResponse` 用于从 `ForgeRouteMap`
  中提取指定层级 + 路由名的具体字段类型。

行为说明：

- 数据源：直接从 Laravel 路由注册表（`Route::getRoutes()`）读取， **不需要启动 HTTP 服务**，离线可用。
- 层级分配逻辑与运行时完全一致（遵循 §3.1.4 五级优先级）。
- 路径参数类型默认 `string | number`（Laravel 路由定义不声明参数类型）。
- body/response 类型默认 `unknown`，由业务侧通过单独的响应类型映射补上类型（避免侵入后端代码）。
- `--level` 过滤时，若层级名不存在则提示可用层级列表；仅生成匹配层级的路由，d.ts 中仅包含该层级分组。
- `--json` 输出二级 JSON 结构（层级 → 路由名），与 d.ts 结构对应。
- 未分配层级的路由（unassigned）不生成类型，因其无层级归属。

> 设计意图：路由定义是后端数据，类型必须由后端这个唯一真相源生成——后端改了路由，跑一次命令前端类型即同步，杜绝手写类型与路由表脱节。前端调用时需要同时传入层级名和路由名（
> `forge.api(level, name, params)`），层级名是加载指定层级路由的前提。相比前端 CLI 请求端点生成，Artisan
> 命令离线可用，CI 中 PHP 构建阶段无需 Node 环境。

#### `php artisan route:forge:clear`

清除 Route Forge 路由元信息缓存，支持全量清除或按层级清除：

```bash
# 清除全部缓存（含摘要端点）
php artisan route:forge:clear
# 仅清除指定层级缓存
php artisan route:forge:clear --level=admin
```

行为说明：

- 全量清除时通过 `RouteCache::clear()` 基于 keys 索引一次性清空所有 `route-forge:*` 缓存键（含摘要端点 `route-forge:summary`）。
- `--level` 清除时仅失效指定层级的缓存键，摘要端点缓存不受影响。
- `--level` 指定的层级名不存在时提示可用层级列表。
- 开发模式（`APP_DEBUG=true`）下缓存本就不写入，执行此命令无实际效果但不会报错。
- 联动清除：执行 Laravel 内置的 `php artisan route:clear` 时，自动连带清除 Route Forge 缓存（通过监听 `CommandStarting` 事件实现）。

### 3.3 管理器页面（开发环境）

仅在 `APP_DEBUG=true`（开发环境）下可用的可视化路由管理面板，提供：

- **层级总览**：卡片式展示各层级路由数量、描述、加载策略（eager/lazy），点击卡片快速过滤
- **路由列表**：表格展示所有命名路由的层级、方法、URI、中间件，支持搜索与按层级/HTTP 方法过滤
- **层级详情**：点击路由名弹出模态框，展示完整元信息（参数、默认值、中间件等）
- **配置编辑**：表单编辑全局设置（端点前缀、缓存 TTL、严格模式等），JSON 编辑器编辑 levels 层级配置，保存后直接写入
  `config/forge.php`

访问地址：

```text
GET /_forge/manager              # 管理器页面（HTML）
GET /_forge/manager/api/routes   # 所有路由及层级分配（JSON）
GET /_forge/manager/api/config   # 当前配置（JSON）
PUT /_forge/manager/api/config   # 更新配置文件
```

行为说明：

- **仅开发环境可用**：`APP_DEBUG=false` 时不注册任何管理器路由，零泄露风险。
- 数据源与 Artisan 命令一致，直接从 Laravel 路由注册表读取，层级分配逻辑与运行时完全一致（遵循 §3.1.4
  五级优先级）。
- 配置保存会重新生成 `config/forge.php` 文件，并自动清除配置缓存使变更立即生效。
- 表格区域限高（`max-height: calc(100vh - 240px)`），路由多时表格内部滚动，表头 sticky 吸顶。
- 前端零构建依赖：Blade 视图 + 原生 CSS + 原生 JavaScript，无需 Node.js 构建流程。

> 设计意图：开发阶段除了 Artisan
> 命令行，开发者还需要一个更直观的可视化界面来理解路由层级分配、调试配置匹配规则、快速编辑配置。管理器页面填补了这一需求，同时严格限制为开发环境专属，不影响生产安全。

## 5. 配置项参考

| 键                                     | 类型                         | 默认值             | 说明                                                                                                                                                                                              |
|----------------------------------------|------------------------------|--------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `levels`                               | `array<string, LevelConfig>` | 见 3.1.2           | 层级定义表，键为层级名（自定义），值为该层级的匹配规则与缓存策略                                                                                                                                  |
| `levels.{name}.description`            | `string`                     | `''`               | 层级描述，仅用于文档与调试输出                                                                                                                                                                    |
| `levels.{name}.match.prefix`           | `string[]`                   | `[]`               | URI 前缀匹配列表，命中任一即归入此层级                                                                                                                                                            |
| `levels.{name}.match.middleware`       | `string[]`                   | `[]`               | 中间件匹配列表，匹配逻辑受 `middleware_match` 控制                                                                                                                                                |
| `levels.{name}.match.middleware_match` | `string\|array`              | `'any'`            | 中间件匹配模式：`'any'`（OR）/ `'all'`（AND）/ DNF 数组（见 §3.1.2 中间件匹配模式）                                                                                                               |
| `levels.{name}.load`                   | `'eager'\|'lazy'`            | `'lazy'`           | 是否在摘要端点中标记为「前端应预加载」；前端自动发现时据此决定预加载策略                                                                                                                          |
| `levels.{name}.endpoint_middleware`    | `string[]\|null`             | `[]`               | 访问该层级元信息端点（`GET /{endpoint_prefix}/{level}`）时要求的中间件列表；未配置或空数组则不限制                                                                                                |
| `endpoint_prefix`                      | `string`                     | `'/_forge/routes'` | 路由元信息对外端点前缀（同时用于层级端点和摘要端点）                                                                                                                                              |
| `url_prefix`                           | `string\|null`               | `null`             | 应用的路由前缀，通过摘要端点 `config.url_prefix` 下发。支持完整 URL（含协议域名）或仅路径前缀；`null` 或空字符串 = 不下发                                                                         |
| `endpoint_middleware`                  | `string[]`                   | `[]`               | 摘要端点（`GET /{endpoint_prefix}`）中间件；空数组或 null 不限制                                                                                                                                  |
| `cache_ttl`                            | `int\|null`                  | `3600`             | 统一缓存 TTL（秒）；`null` 不缓存，`0` 永久缓存，负值视为 `null`（不缓存）。同时作用于所有层级端点与摘要端点。⚠️ `0` 遵循 Laravel Cache TTL 惯例（永久），非 HTTP `Cache-Control: max-age=0` 含义 |
| `cache_driver`                         | `string\|null`               | `null`             | 缓存驱动；`null` 用默认驱动，可指定 `redis`/`file`/`array` 等                                                                                                                                     |
| `strict_mode`                          | `bool`                       | `false`            | 严格模式；未命中层级时抛异常（true）或归入 `unassigned` 特殊层级（false）                                                                                                                         |
| `scheme_version`                       | `int`                        | `1`                | 摘要端点返回的响应格式版本号（`schemeVersion` 字段）；后续迭代引入不兼容的格式变更时递增，前端据此做版本兼容                                                                                      |
| `classifier`                           | `callable\|null`             | `null`             | 自定义分类回调，签名 `fn(Route $r): ?string`，返回层级名或 null。返回的层级名必须在 `levels` 配置中存在，否则抛 `UnknownClassifierTierException`                                                  |

## 6. 错误码

所有 Route Forge 抛出的异常都实现 `ForgeExceptionContract` 契约，提供 `code()`（错误码）与 `httpStatus()`（对应 HTTP 状态码）方法，便于调用方 catch 后统一处理与错误响应映射。

| 错误类                                  | code        | 触发场景                                                                | HTTP 状态 |
|-----------------------------------------|-------------|-------------------------------------------------------------------------|-----------|
| `RouteTierNotAssignedException`         | `RF_BE_001` | `strict_mode=true` 且路由未命中任何层级                                 | 500       |
| `UnknownLevelException`                 | `RF_BE_002` | 请求的层级名不在 `levels` 配置中                                        | 404       |
| `CacheDriverException`                  | `RF_BE_003` | 指定的 `cache_driver` 不可用                                            | 500       |
| `ClassifierException`                   | `RF_BE_004` | `classifier` 回调抛错                                                   | 500       |
| `RouteMissingNameException`             | `RF_BE_005` | `strict_mode=true` 且路由设置了 tier 但没有路由名                       | 500       |
| `UnknownClassifierTierException`        | `RF_BE_006` | `classifier` 返回的层级名不在 `levels` 配置中                           | 500       |
| `DiscardedRegistrarAttributesException` | `RF_BE_007` | `strict_mode=true` 且 `Route::group(...)->tier(...)` 尾部链式属性被丢弃 | 500       |

## 7. 测试矩阵

| 测试维度     | 覆盖点                                                                                                                                 |
|--------------|----------------------------------------------------------------------------------------------------------------------------------------|
| 层级分配     | 显式 `->tier()`、资源路由（resource/apiResource/singleton）tier、配置 match、`Route::group` 透传、classifier、unassigned 兜底、优先级覆盖、**多层级同时命中取最后一个** |
| Artisan 命令 | `route:forge:list` 输出格式（table/json）、按层级过滤、unassigned 路由显示、`--level` 参数过滤                                         |
| Artisan 命令 | `route:forge:types` 生成 d.ts 二级结构（层级 → 路由名）、`--level` 过滤、`--json` 二级 JSON 输出、`--out` 写文件                       |
| Artisan 命令 | `route:forge:clear` 全量清除缓存、按层级清除、无效层级名报错                                                                           |
| 中间件匹配   | `middleware_match` 简单模式（any/all）、高级模式（DNF 数组）、边界情况（空数组、单元素）                                               |
| 端点响应     | `/_forge/routes/{level}` 返回结构、`/_forge/routes` 摘要端点返回结构、缓存命中、未声明层级 404、层级端点中间件保护、摘要端点中间件保护 |
| 严格模式     | `strict_mode=true` 未命中抛异常、`false` 未命中归入 unassigned 特殊层级                                                              |
| Laravel 兼容 | CI（GitHub Actions）跑 PHP 8.2–8.4 × Laravel 11/12 矩阵；Laravel 13 经 composer 约束支持；资源路由、嵌套 group、链式语法、Router 重绑共享 RouteCollection |
| 缓存         | `array`/`file` 驱动（store 无关，`redis` 复用同一 `Repository` 接口，无专属逻辑）、TTL 正数过期、手动失效、`0` 永久缓存、`cache_ttl=null` 不缓存、keys 索引清理、debug 模式跳过读写 |
| 管理器页面   | `GET /_forge/manager` 页面渲染、`/api/routes`（含 forge 自身路由过滤与 unassigned 归属）、`/api/config` 读取、配置生成器层级名转义（`php -l` 实测防注入）；仅 `APP_DEBUG=true` 注册 |

## 8. 版本与发布

### 8.1 v1.0 能力清单（MVP）

- ✅ 层级分配（3 种方式）：显式 `->tier()` 宏（含资源路由 / singleton 链式）、`Route::group` 透传、配置文件批量匹配
- ✅ 五级优先级：显式标注 > 分组继承 > 自定义回调 > 配置匹配 > 兜底/未分配
- ✅ 元信息端点：按层级返回路由元信息（名称 + URI + method + 参数）
- ✅ 摘要端点：返回所有层级概览与全局配置，供前端初始化自动发现
- ✅ `middleware_match`：支持 `any` / `all` / DNF 数组三种匹配模式
- ✅ 统一缓存：所有层级端点与摘要端点共享同一 TTL 配置，支持多种缓存驱动
- ✅ Artisan 命令：`route:forge:list` 查看层级分配结果、`route:forge:types` 生成 TS 类型声明、`route:forge:clear` 清除缓存
- ✅ 管理器页面：开发环境专属的可视化路由管理面板（层级总览、路由搜索/过滤、配置编辑）

### 8.2 v1.x 路线图

- ✅ v1.1：可视化路由管理面板（Blade + 原生 JS，开发环境专属）
- v1.2：OpenAPI 桥接（从 OpenAPI spec 生成 Route Forge 类型）
- v1.3：Vite 插件（dev 时自动 codegen，HMR 同步路由变更）

### 8.3 兼容性承诺

- Laravel：支持当前活跃维护的 3 个大版本（v1 发布时为 11/12/13）；新版本发布 6 个月内适配。
- PHP：支持 8.2+。