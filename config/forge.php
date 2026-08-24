<?php

declare(strict_types=1);

/**
 * 默认配置（route-forge/laravel）
 *
 * 发布到宿主项目后通过 config/forge.php 修改。
 *
 * @see .docs/SPEC.md §3.1.2, §5
 */
return [

    /*
    |--------------------------------------------------------------------------
    | 层级定义表
    |--------------------------------------------------------------------------
    |
    | 键为层级名（完全可自定义，包不预设固定层级）。详见 DESIGN.md §6.2。
    |
    | 每个层级的可用字段（对应 SPEC §5）：
    |
    |   description            string   ''    层级描述，仅用于文档与调试输出
    |   match.prefix           string[] []    URI 前缀匹配列表，命中任一即归入此层级
    |   match.middleware       string[] []    中间件匹配列表，匹配逻辑受 middleware_match 控制
    |   match.middleware_match string|  'any' 中间件匹配模式：
    |                          array          'any'（OR）/ 'all'（AND）/ DNF 数组（见 §3.1.2）
    |   load                   'eager'|'lazy'
    |                              'eager'    是否在摘要端点中标记为「前端应预加载」
    |                              'lazy'     前端自动发现时据此决定预加载策略
    |   endpoint_middleware    string[]       访问该层级元信息端点时要求的中间件列表，未配置则不限制
    |                          |null
    |
    */
    'levels'            => [
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
        'admin'  => [
            'description' => '系统管理接口',
            'match' => [
                'prefix'     => ['admin'],
                'middleware' => ['auth', 'admin'],
                'middleware_match' => 'all',
            ],
            'load'  => 'lazy',
            // 'endpoint_middleware' => ['auth', 'admin'],  // 访问 /_forge/routes/admin 需要 auth + admin
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由元信息端点前缀
    |--------------------------------------------------------------------------
    |
    | 同时用于层级端点（GET /{prefix}/{level}）和摘要端点（GET /{prefix}）。
    | 可通过 FORGE_ENDPOINT_PREFIX 环境变量覆盖。
    |
    */
    'endpoint_prefix'   => env('FORGE_ENDPOINT_PREFIX', '/_forge/routes'),

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（url_prefix）
    |--------------------------------------------------------------------------
    |
    | 应用的路由前缀，通过摘要端点 config.url_prefix 下发给前端。
    | 支持两种格式：
    |   - 完整 URL（含协议和域名）：'https://api.example.com/v1'
    |   - 仅路径前缀：'/api/v1'
    |
    | null 或空字符串 = 不下发（前端视为无前缀）。
    |
    */
    'url_prefix' => env('FORGE_URL_PREFIX'),

    /*
    |--------------------------------------------------------------------------
    | 摘要端点中间件
    |--------------------------------------------------------------------------
    |
    | 访问摘要端点（GET /{endpoint_prefix}）时要求的中间件列表。
    | null 或空数组 = 不限制访问。
    |
    */
    'endpoint_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | 缓存 TTL（秒）
    |--------------------------------------------------------------------------
    |
    | 统一控制所有层级端点与摘要端点的缓存 TTL。
    | null = 不缓存（每次实时扫描路由表）
    | 0 = 永久缓存（Laravel Cache 惯例，
    | ⚠ 非 HTTP Cache-Control: max-age=0 含义）。
    | 负值 = 视为 null（不缓存）。
    |
    */
    'cache_ttl'         => env('FORGE_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | 缓存驱动
    |--------------------------------------------------------------------------
    |
    | null = 使用默认缓存驱动；可指定 redis / file / array 等。
    |
    */
    'cache_driver'      => env('FORGE_CACHE_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | 严格模式
    |--------------------------------------------------------------------------
    |
    | true  = 未命中层级的路由抛 RouteTierNotAssignedException。
    | false = 未命中路由归入 fallback_level（非 null 时）或「未分配」分组。
    |
    */
    'strict_mode'       => env('FORGE_STRICT_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | 兜底层级名
    |--------------------------------------------------------------------------
    |
    | strict_mode=false 时生效。
    | null   = 不兜底，未命中路由归入 unassigned 特殊层级，
    |          经 GET /{endpoint_prefix}/unassigned 获取（见 SPEC §3.1.6）。
    | 非 null = 未命中路由归入指定层级。
    |
    */
    'fallback_level'    => env('FORGE_FALLBACK_LEVEL'),

    /*
    |--------------------------------------------------------------------------
    | 摘要端点响应格式版本（schemeVersion）
    |--------------------------------------------------------------------------
    |
    | 摘要端点（GET /{endpoint_prefix}）返回的响应格式版本号，
    | 前端据此识别格式并做版本兼容。默认 1，后续迭代引入不兼容的
    | 格式变更时递增。
    |
    */
    'scheme_version'    => env('FORGE_SCHEME_VERSION', 1),

    /*
    |--------------------------------------------------------------------------
    | 自定义分类回调
    |--------------------------------------------------------------------------
    |
    | 签名 fn(Route $r): ?string，返回层级名或 null。
    | 优先级介于「显式 tier / group tier」与「配置 match」之间（见 SPEC §3.1.4）。
    | 返回的层级名必须在 levels 配置中存在，否则抛 UnknownClassifierTierException。
    |
    | 示例：按 Controller 命名空间归类
    |   'classifier' => fn(\Illuminate\Routing\Route $r): ?string => str_contains($r->getAction()['controller'] ?? '', 'Admin\\') ? 'admin' : null,
    |
    */
    'classifier'        => null,
];
