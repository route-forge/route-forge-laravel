<?php

declare(strict_types=1);

/**
 * 默认配置（route-forge/laravel）
 *
 * 发布到宿主项目后通过 config/forge.php 修改。
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
    |   description            string   ''              层级描述，仅用于文档与调试输出
    |   match.prefix           string[] []              URI 前缀匹配列表，命中任一即归入此层级
    |   match.middleware       string[] []              中间件匹配列表，匹配逻辑受 middleware_match 控制
    |   match.middleware_match string|  'any'           中间件匹配模式：
    |                          array                    'any'（OR）/ 'all'（AND）/ DNF 数组（见 §3.1.2）
    |   load                   'eager'  'lazy'         是否在摘要端点中标记为「前端应预加载」
    |                          |'lazy'                  前端自动发现时据此决定预加载策略
    |
    */

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
            'load' => 'lazy',
        ],
        'manage' => [
            'description' => '运营管理接口',
            'match' => [
                'prefix'     => ['manage'],
                'middleware' => ['auth', 'manage'],
            ],
            'load' => 'lazy',
        ],
        'admin' => [
            'description' => '系统管理接口',
            'match' => [
                'prefix'     => ['admin'],
                'middleware' => ['auth', 'admin'],
                'middleware_match' => 'all',
            ],
            'load' => 'lazy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | 路由元信息端点前缀
    |--------------------------------------------------------------------------
    |
    | 同时用于层级端点（GET /{prefix}/{level}）和摘要端点（GET /{prefix}）。
    |
    */

    'endpoint_prefix' => env('FORGE_ENDPOINT_PREFIX', '/_forge/routes'),

    /*
    |--------------------------------------------------------------------------
    | 缓存 TTL（秒）
    |--------------------------------------------------------------------------
    |
    | 统一控制所有层级端点与摘要端点的缓存 TTL。
    | null = 不缓存（每次实时扫描路由表）；0 = 永久缓存（Laravel Cache 惯例）。
    | ⚠ 非 HTTP Cache-Control: max-age=0 含义。
    |
    */

    'cache_ttl' => env('FORGE_CACHE_TTL', 3600),

    /*
    |--------------------------------------------------------------------------
    | 缓存驱动
    |--------------------------------------------------------------------------
    |
    | null = 使用默认缓存驱动；可指定 redis / file / array 等。
    |
    */

    'cache_driver' => env('FORGE_CACHE_DRIVER'),

    /*
    |--------------------------------------------------------------------------
    | 严格模式
    |--------------------------------------------------------------------------
    |
    | true  = 未命中层级的路由抛 RouteTierNotAssignedException。
    | false = 未命中路由归入 fallback_level（非 null 时）或「未分配」分组。
    |
    */

    'strict_mode' => env('FORGE_STRICT_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | 兜底层级名
    |--------------------------------------------------------------------------
    |
    | strict_mode=false 时生效。
    | null   = 不兜底，未命中路由归入摘要端点的 unassigned 列表（见 SPEC §3.1.6）。
    | 非 null = 未命中路由归入指定层级。
    |
    */

    'fallback_level' => env('FORGE_FALLBACK_LEVEL'),

    /*
    |--------------------------------------------------------------------------
    | 自定义分类回调
    |--------------------------------------------------------------------------
    |
    | 签名 fn(Route $r): ?string，返回层级名或 null。
    | 优先级介于「显式 tier / group tier」与「配置 match」之间（见 SPEC §3.1.4）。
    |
    */

    'classifier' => null,
];
