# Route Forge for Laravel

> Laravel 命名路由的分级懒加载后端：路由扫描、tier 分配、元信息端点、缓存与类型生成。

## 📦 仓库来源

本仓库自 **route-forge monorepo**（[github.com/xyj2156/route-forge](https://github.com/xyj2156/route-forge)）拆分而来，仅承载其中的 **`route-forge/laravel` composer 包**（后端部分）。

- 前端包（`@route-forge/core`、`@route-forge/vue`）仍在原 monorepo 中维护；
- 规划文档随拆分迁移至本仓库 [`.docs`](./.docs) 目录，作为前后端契约的单一真相源：
  - [`.docs/SPEC.md`](./.docs/SPEC.md)：功能规格说明书（本仓库对应 §3 后端功能、§5.1 配置项、§6.1 错误码）；
  - [`.docs/DESIGN.md`](./.docs/DESIGN.md)：设计思路与关键技术决策。

## 功能概览

Route Forge 后端提供多种互相兼容的路由层级（tier）分配方式，可任选其一或组合使用。层级名完全由项目自定义，包不预设固定层级：

1. **`->tier()` 宏**：定义路由时显式标记，链式调用对资源路由同样生效；
2. **`Route::group` 的 `tier` 选项**：整组路由继承层级，嵌套 group 内层覆盖外层；
3. **链式 `tier()` 语法**：`Route::tier('admin')->group(...)` 流式写法，可与 `middleware`/`prefix`/`as` 等任意属性自由链式组合；
4. **配置文件按规则批量分配**：`config/forge.php` 中按 URI 前缀 / 中间件（支持 `any` / `all` / DNF 数组三种匹配模式）批量归类。

此外还包括：

- **五级优先级**：显式 `->tier()` > group 透传 > `classifier` 回调 > 配置 match > fallback/unassigned；
- **元信息端点** `GET /_forge/routes/{level}`：按层级返回路由元信息（名称 + URI + method + 参数），供前端按需懒加载；
- **摘要端点** `GET /_forge/routes`：返回所有层级概览、全局配置与未分配路由列表，供前端初始化自动发现；
- **统一缓存**：所有层级端点与摘要端点共享同一 TTL 配置，`cache_driver` 可指定驱动；开发模式（`APP_DEBUG=true`）自动跳过缓存；
- **Artisan 命令**：`route:forge:list` 查看层级分配结果、`route:forge:types` 生成 TS 类型声明；
- **严格模式**：`strict_mode=true` 时未命中层级抛 `RouteTierNotAssignedException`。

## 环境要求

- PHP `^8.2`
- Laravel（illuminate）`^11.0 || ^12.0 || ^13.0`

## 安装

```bash
composer require route-forge/laravel

# 发布配置文件（可选，默认配置开箱可用）
php artisan vendor:publish --tag=forge-config
```

ServiceProvider 已通过 composer 包发现自动注册。

## 快速上手

### 分配层级

```php
// 方式一：显式标记
Route::post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login')
    ->tier('public');

// 方式二：分组继承（数组语法）
Route::group([
    'prefix'     => 'admin',
    'middleware' => ['auth', 'admin'],
    'tier'       => 'admin',
], function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// 方式三：链式语法（tier 作为流式属性，可与任意路由属性自由组合）
Route::tier('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// 链式组合：tier 与 middleware / prefix / as 等任意顺序拼接
Route::middleware(['auth', 'admin'])->tier('admin')->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// group 后追加 tier（效果等价于数组语法中的 'tier' 键）
Route::group([
    'prefix' => 'admin',
], function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
})->tier('admin');

// 方式四：配置批量匹配（config/forge.php）
// 'admin' => [
//     'match' => ['prefix' => ['admin'], 'middleware' => ['auth', 'admin']],
//     'load'  => 'lazy',
// ],
```

### 前端拉取元信息

```
GET /_forge/routes          # 所有层级摘要 + 全局配置 + unassigned 列表
GET /_forge/routes/admin    # admin 层级下所有命名路由的元信息
```

### Artisan 命令

```bash
# 查看所有路由的层级分配（--level 过滤、--json 输出、--unassigned 仅看未分配）
php artisan route:forge:list

# 生成 TS 类型声明（默认 stdout；--out 写文件；--level / --json 可选）
php artisan route:forge:types
```

## 主要配置项（config/forge.php）

| 键                | 类型             | 默认值             | 说明                                        |
|-------------------|------------------|--------------------|---------------------------------------------|
| `levels`          | `array`          | 见配置文件         | 层级定义表（匹配规则、加载策略）            |
| `endpoint_prefix` | `string`         | `'/_forge/routes'` | 路由元信息对外端点前缀                      |
| `cache_ttl`       | `int\|null`      | `3600`             | 统一缓存 TTL（秒）；null 不缓存            |
| `cache_driver`    | `string\|null`   | `null`             | 缓存驱动；null 使用默认驱动                 |
| `strict_mode`     | `bool`           | `false`            | 未命中层级时抛异常（true）或走兜底（false） |
| `fallback_level`  | `string\|null`   | `null`             | 兜底层级；null 时归入「未分配」分组         |
| `classifier`      | `callable\|null` | `null`             | 自定义分类回调 `fn(Route $r): ?string`      |

完整配置项参考见 [`.docs/SPEC.md` §5.1](./.docs/SPEC.md)。

### 开发模式

当 `APP_DEBUG=true`（Laravel 默认的开发环境配置）时，Route Forge 自动跳过所有缓存读写操作，路由变更即时生效，无需手动清除缓存。生产环境（`APP_DEBUG=false`）下统一使用 `cache_ttl` 配置控制缓存。

## 开发

```bash
composer install
composer test            # 运行 PHPUnit 测试套件
composer test:coverage   # 文本覆盖率报告
```

测试基于 [orchestra/testbench](https://github.com/orchestral/testbench)，覆盖层级分配优先级、中间件匹配（any/all/DNF）、端点响应、缓存、严格模式与两个 Artisan 命令。

## License

[MIT](./LICENSE)
