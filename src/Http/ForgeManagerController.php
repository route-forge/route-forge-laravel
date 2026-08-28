<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\App;
use RouteForge\Laravel\RouteRepository;

/**
 * 管理器页面控制器：仅在开发环境（APP_DEBUG=true）下可用。
 *
 * 提供可视化路由管理面板，包括：
 *   - 路由列表与层级总览
 *   - 路由搜索与过滤
 *   - 层级详情查看
 *   - 配置编辑（levels + 全局设置）
 */
class ForgeManagerController extends Controller
{
    public function __construct(private readonly RouteRepository $repository) {}

    /**
     * 管理器页面（HTML）。
     */
    public function index(): \Illuminate\Contracts\View\View
    {
        $levelsConfig = config('forge.levels', []);
        $tiers        = [];
        foreach ($levelsConfig as $name => $cfg) {
            $tiers[] = [
                'name'        => $name,
                'description' => $cfg['description'] ?? '',
                'load'        => $cfg['load'] ?? 'lazy',
            ];
        }

        return view('forge::manager', [
            'tiers'        => $tiers,
            'levelsConfig' => $levelsConfig,
            'globalConfig' => [
                'endpoint_prefix' => (string)config('forge.endpoint_prefix', '/_forge/routes'),
                'url_prefix'      => config('forge.url_prefix'),
                'cache_ttl'       => config('forge.cache_ttl'),
                'cache_driver'    => config('forge.cache_driver'),
                'strict_mode'     => (bool)config('forge.strict_mode', false),
                'scheme_version'  => (int)config('forge.scheme_version', 1),
            ],
        ]);
    }

    /**
     * API：获取所有路由及层级分配（JSON）。
     */
    public function routes(): JsonResponse
    {
        $data = $this->repository->getAllRoutesWithTiers();

        return new JsonResponse([
            'routes' => $data['routes'],
            'tiers'  => $data['tiers'],
        ]);
    }

    /**
     * API：获取当前配置（JSON）。
     */
    public function config(): JsonResponse
    {
        return new JsonResponse([
            'levels' => config('forge.levels', []),
            'global' => [
                'endpoint_prefix' => (string)config('forge.endpoint_prefix', '/_forge/routes'),
                'url_prefix'      => config('forge.url_prefix'),
                'cache_ttl'       => config('forge.cache_ttl'),
                'cache_driver'    => config('forge.cache_driver'),
                'strict_mode'     => (bool)config('forge.strict_mode', false),
                'scheme_version'  => (int)config('forge.scheme_version', 1),
            ],
        ]);
    }

    /**
     * API：更新配置文件。
     *
     * 接收 levels（JSON 对象）与 global（全局设置对象），
     * 重新生成 config/forge.php 文件。
     */
    public function updateConfig(Request $request): JsonResponse
    {
        $levels = $request->input('levels');
        $global = $request->input('global');

        if (!is_array($levels)) {
            return new JsonResponse(['error' => 'Invalid levels data'], 422);
        }
        if (!is_array($global)) {
            return new JsonResponse(['error' => 'Invalid global config'], 422);
        }

        $configPath = App::configPath('forge.php');
        $content    = $this->generateConfigContent($levels, $global);

        if (@file_put_contents($configPath, $content) === false) {
            return new JsonResponse(['error' => 'Failed to write config file'], 500);
        }

        // 清除配置缓存使变更立即生效
        App::forgetInstance('config');
        App::make('config');

        return new JsonResponse(['success' => true, 'message' => 'Config updated successfully']);
    }

    // ─── Config File Generation ────────────────────────────────────────

    /**
     * 生成 config/forge.php 文件内容。
     */
    private function generateConfigContent(array $levels, array $global): string
    {
        $levelsCode = $this->formatLevelsArray($levels);

        $endpointPrefix = $this->exportScalar($global['endpoint_prefix'] ?? '/_forge/routes');
        $urlPrefix      = $this->exportScalar($global['url_prefix'] ?? null);
        $cacheTtl       = $this->exportScalar($global['cache_ttl'] ?? 3600);
        $cacheDriver    = $this->exportScalar($global['cache_driver'] ?? null);
        $strictMode     = ($global['strict_mode'] ?? false) ? 'true' : 'false';
        $schemeVersion  = (int)($global['scheme_version'] ?? 1);

        return <<<PHP
<?php

declare(strict_types=1);

/**
 * Route Forge 配置文件（由管理器页面生成）
 *
 * @see .docs/SPEC.md §3.1.2, §5
 */
return [

    /*
    |--------------------------------------------------------------------------
    | 层级定义表
    |--------------------------------------------------------------------------
    */
    'levels'            => {$levelsCode},

    /*
    |--------------------------------------------------------------------------
    | 路由元信息端点前缀
    |--------------------------------------------------------------------------
    */
    'endpoint_prefix'   => {$endpointPrefix},

    /*
    |--------------------------------------------------------------------------
    | 路由前缀（url_prefix）
    |--------------------------------------------------------------------------
    */
    'url_prefix' => {$urlPrefix},

    /*
    |--------------------------------------------------------------------------
    | 摘要端点中间件
    |--------------------------------------------------------------------------
    */
    'endpoint_middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | 缓存 TTL（秒）
    |--------------------------------------------------------------------------
    */
    'cache_ttl'         => {$cacheTtl},

    /*
    |--------------------------------------------------------------------------
    | 缓存驱动
    |--------------------------------------------------------------------------
    */
    'cache_driver'      => {$cacheDriver},

    /*
    |--------------------------------------------------------------------------
    | 严格模式
    |--------------------------------------------------------------------------
    */
    'strict_mode'       => {$strictMode},

    /*
    |--------------------------------------------------------------------------
    | 摘要端点响应格式版本（schemeVersion）
    |--------------------------------------------------------------------------
    */
    'scheme_version'    => {$schemeVersion},

    /*
    |--------------------------------------------------------------------------
    | 自定义分类回调
    |--------------------------------------------------------------------------
    */
    'classifier'        => null,
];

PHP;
    }

    /**
     * 格式化 levels 配置数组为 PHP 代码字符串。
     */
    private function formatLevelsArray(array $levels): string
    {
        if (empty($levels)) {
            return '[]';
        }

        $parts = [];
        foreach ($levels as $name => $config) {
            $parts[] = $this->formatLevelEntry((string)$name, $config);
        }

        return "[\n" . implode("\n", $parts) . '    ]';
    }

    /**
     * 格式化单个层级配置条目。
     *
     * 层级名经 var_export 转义后拼入，避免特殊字符破坏
     * 生成的 PHP 文件语法（甚至注入代码）。
     */
    private function formatLevelEntry(string $name, array $config): string
    {
        $i       = '        '; // 8-space indent
        $lines   = [];
        $lines[] = "{$i}" . var_export($name, true) . ' => [';

        $lines[] = "{$i}    'description' => " . var_export($config['description'] ?? '',
                true) . ',';

        $match   = $config['match'] ?? [];
        $lines[] = "{$i}    'match' => " . $this->formatMatchArray($match, $i . '    ') . ',';

        $lines[] = "{$i}    'load'  => " . var_export($config['load'] ?? 'lazy', true) . ',';

        if (isset($config['endpoint_middleware']) && !empty($config['endpoint_middleware'])) {
            $lines[] = "{$i}    'endpoint_middleware' => " . $this->exportInlineArray($config['endpoint_middleware']) . ',';
        }

        $lines[] = "{$i}],";

        return implode("\n", $lines);
    }

    /**
     * 格式化 match 规则数组。
     */
    private function formatMatchArray(array $match, string $indent): string
    {
        $parts = [];

        $prefix     = $match['prefix'] ?? [];
        $middleware = $match['middleware'] ?? [];
        $parts[]    = "'prefix' => " . $this->exportInlineArray($prefix);
        $parts[]    = "'middleware' => " . $this->exportInlineArray($middleware);

        if (isset($match['middleware_match'])) {
            $mm = $match['middleware_match'];
            if (is_string($mm)) {
                $parts[] = "'middleware_match' => " . var_export($mm, true);
            } elseif (is_array($mm)) {
                $parts[] = "'middleware_match' => " . $this->exportDnfArray($mm, $indent);
            }
        }

        return "[\n" . $indent . '    ' . implode(",\n" . $indent . '    ',
                $parts) . ",\n" . $indent . ']';
    }

    /**
     * 将简单索引数组导出为单行 PHP 数组字面量。
     */
    private function exportInlineArray(array $arr): string
    {
        if (empty($arr)) {
            return '[]';
        }
        $items = array_map(fn($v) => var_export($v, true), array_values($arr));
        return '[' . implode(', ', $items) . ']';
    }

    /**
     * 将 DNF 嵌套数组格式化为多行 PHP 数组字面量。
     */
    private function exportDnfArray(array $dnf, string $indent): string
    {
        $groups = [];
        foreach ($dnf as $group) {
            if (is_array($group)) {
                $groups[] = '[' . implode(', ', array_map('intval', $group)) . ']';
            }
        }
        return "[\n" . $indent . '        ' . implode(",\n" . $indent . '        ',
                $groups) . ",\n" . $indent . '    ]';
    }

    /**
     * 导出标量值（null/string/int/bool）。
     */
    private function exportScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }
        return var_export((string)$value, true);
    }
}
