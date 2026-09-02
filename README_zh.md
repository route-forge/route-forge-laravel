# Route Forge for Laravel

**在 Vue / React / Inertia 单页应用（SPA）里直接使用 Laravel 的命名路由——不必硬编码 URL，也不必把整张路由表打包给前端。**

Route Forge 通过一个轻量的 HTTP 元信息端点把 Laravel 的命名路由暴露出去，支持**按层级（tier）拆分并按需懒加载**，并**生成 TypeScript 类型**，让前端的路由名与参数都具备类型安全。它零注解即可工作——直接读取 Laravel 自己的路由注册表。

[![Latest Version on Packagist](https://img.shields.io/packagist/v/route-forge/laravel.svg?style=flat-square)](https://packagist.org/packages/route-forge/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/route-forge/laravel.svg?style=flat-square)](https://packagist.org/packages/route-forge/laravel)
[![PHP](https://img.shields.io/packagist/php-v/route-forge/laravel.svg?style=flat-square)](#环境要求)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-red.svg?style=flat-square)](#环境要求)
[![Tests](https://img.shields.io/github/actions/workflow/status/route-forge/route-forge-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/route-forge/route-forge-laravel/actions)
[![License](https://img.shields.io/github/license/route-forge/route-forge-laravel.svg?style=flat-square)](./LICENSE)

**语言 / Language:** [English](./README.md) · [简体中文](./README_zh.md)

> **面向 AI 助手 / 编码 Agent：** 本包为 [`route-forge/laravel`](https://packagist.org/packages/route-forge/laravel)。机器可读概览见 [`llms.txt`](./llms.txt)，Agent 集成指南见 [`AGENTS.md`](./AGENTS.md)（英文）。

## 解决什么问题

当 Laravel 后端由一个 SPA（Vue / React / Inertia / 独立部署的移动端 Web）承接时，前端需要拼接指向后端接口的 URL。常见做法各有各的痛：

- **在前端硬编码 URL**：与后端的路由知识重复、容易脱节、且易写错。
- **一次性注入整张路由表**（比如挂成 JS 全局）：体积随应用增长，还会下发当前用户根本访问不到的路由。
- **每个接口手写 API 客户端**：每个项目重复造轮子，且没有类型安全。

Route Forge 让后端路由表成为**单一事实来源（single source of truth）**：前端在运行时按层级、按需获取它需要的东西，并从权威路由注册表直接生成 TypeScript 类型。

## 为什么选 Route Forge（能力自述）

- **分级懒加载**——用 `->tier()`、`Route::group(['tier' => ...])`、`Route::tier(...)->group(...)`、配置匹配规则或 `classifier` 回调给路由打层级；前端只加载当前需要的层级，而不是整张路由表。
- **自动发现摘要端点**——`GET /_forge/routes` 返回所有层级概览、全局配置与未分配路由，新接入的客户端无需任何硬编码配置即可自举。
- **TypeScript 类型生成**——`php artisan route:forge:types` 从真实路由注册表产出 `.d.ts`（离线可用、无需 HTTP 服务、CI 友好）。
- **后端权威配置**——`strict_mode`、URL 前缀、各层级加载策略都由后端掌控，经摘要端点下发给客户端，前后端永不打架。
- **零注解、零侵入**——完全基于 Laravel 扩展点（macro + ServiceProvider）实现，读取 `Route::getRoutes()`，绝不修改框架。
- **缓存与开发体验**——统一 TTL / 缓存驱动，`APP_DEBUG` 下自动跳过缓存，外加一个仅开发环境可用的可视化路由管理器。

> **全栈搭配：** 本仓库是 route-forge 项目的**后端**那一半。搭配配套前端 SDK [`@route-forge/core`](https://www.npmjs.com/package/@route-forge/core)（以及 `@route-forge/vue` / `@route-forge/react`），可获得请求封装、并发控制与登录态感知的层级加载。

## 功能概览

Route Forge 提供多种可互换、可组合的层级分配方式。层级名完全由你定义——包本身不预设任何固定层级。

1. **`->tier()` 宏**——定义路由时显式标记，链式调用，对资源路由同样生效。
2. **`Route::group` 的 `tier` 选项**——整组继承层级，嵌套 group 内层覆盖外层。
3. **链式 `Route::tier(...)->group(...)`**——第 2 种的流式写法，可与 `middleware` / `prefix` / `as` 任意顺序组合。
4. **配置驱动的批量分配**——`config/forge.php` 按 URI 前缀 / 中间件（`any` / `all` / DNF 数组匹配）批量归类。

此外还包括：

- **五级优先级**：显式 `->tier()` > group 透传 > `classifier` 回调 > 配置匹配 > `unassigned` 兜底。
- **元信息端点** `GET /_forge/routes/{level}`——按层级返回路由元信息（名称 + URI + method + 参数），供懒加载。
- **摘要端点** `GET /_forge/routes`——返回所有层级概览 + 全局配置 + 未分配信息，供客户端自动发现。
- **统一缓存**——所有端点共享同一 TTL 与 `cache_driver`；开发模式（`APP_DEBUG=true`）自动跳过。
- **Artisan 命令**——`route:forge:list`、`route:forge:types`、`route:forge:clear`。
- **严格模式**——`strict_mode=true` 时未命中层级抛 `RouteTierNotAssignedException`；否则归入 `unassigned`。
- **管理器页面**——仅开发环境可用的可视化面板 `GET /_forge/manager`（总览、搜索/过滤、配置编辑）。

## 环境要求

- PHP `^8.2`
- Laravel（illuminate）`^11.0 || ^12.0 || ^13.0`

## 安装

```bash
composer require route-forge/laravel

# 发布配置文件（可选，默认配置开箱可用）
php artisan vendor:publish --tag=forge-config
```

ServiceProvider 通过 Laravel 包发现自动注册。

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

// 方式三：链式语法（tier 可与任意路由属性、任意顺序组合）
Route::middleware(['auth', 'admin'])->tier('admin')->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// 方式四：配置批量匹配（config/forge.php）
// 'admin' => [
//     'match' => ['prefix' => ['admin'], 'middleware' => ['auth', 'admin']],
//     'load'  => 'lazy',
// ],
```

> ⚠️ **组级属性必须在 `group()` 之前声明。**
> `Route::group([...], fn)->tier('admin')` 这类写法**不会**生效——Laravel 的 `group()` 在返回前
> 已完成组内路由注册并将组属性出栈，其后的链式调用无法回溯到这些路由。
> 正确写法二选一：数组语法 `Route::group(['tier' => 'admin', ...], fn)`，
> 或前置链式 `Route::tier('admin')->group(fn)`。

### 前端拉取元信息

```
GET /_forge/routes              # 摘要：所有层级（含特殊层级 unassigned）+ 全局配置 + schemeVersion
GET /_forge/routes/admin        # admin 层级下所有命名路由的元信息
GET /_forge/routes/unassigned   # 未命中任何层级的路由元信息
```

```js
// 初始化：发现可用层级
const summary = await fetch('/_forge/routes').then(r => r.json());
// 按需：只加载需要的层级
const adminRoutes = await fetch('/_forge/routes/admin').then(r => r.json());
```

### 可选：把摘要内嵌进首页

如果你的前端 HTML 由 Laravel（Blade）服务端渲染，可以用 `@forgeSummary` 彻底省掉首屏那次摘要往返。把它放进 `<head>`（早于前端 bundle），它会以**一次性、消费即自删、不可枚举**的 `window.__ROUTE_FORGE__` 访问器把**摘要端点的返回值**内嵌进页面，`@route-forge/core` 初始化时读一次即可：

```blade
<head>
    {{-- ... --}}
    @forgeSummary
</head>
```

它是叠加在既有端点之上的**纯加速**，不改变端点契约：

- **只嵌摘要**——各层级明细仍按 `GET /_forge/routes/{level}` 走 HTTP 懒加载（受保护层级的路由数据不得预置进公开 HTML）。
- 复用摘要端点的**同一 producer / 缓存**（逐字段一致）、**不新增 HTTP 端点**、**不递增 `schemeVersion`**。
- 纯 SPA 独立部署 / Vite dev 不书写该指令即不注入，自动回落网络摘要，行为不变。

> 一次性自删访问器只缩小数据在 `window` 上的运行时驻留面；摘要数据仍随 HTML 源码可见，**不是**抗 XSS / 抗网络窃取的硬边界，切勿当作安全机制。

### Artisan 命令

```bash
# 查看层级分配结果（--level 过滤、--json、--unassigned 仅看未分配）
php artisan route:forge:list

# 生成 TypeScript 类型声明（默认输出到 stdout；--out 写文件；--level / --json 可选）
php artisan route:forge:types

# 清除路由元信息缓存（--level 仅清指定层级；不传则清全部）。
# 执行 Laravel 内置的 php artisan route:clear 时也会自动连带清除。
php artisan route:forge:clear
```

## 主要配置项（config/forge.php）

| 键 | 类型 | 默认值 | 说明 |
|-----|------|--------|------|
| `levels` | `array` | 见配置文件 | 层级定义表（匹配规则、加载策略） |
| `endpoint_prefix` | `string` | `'/_forge/routes'` | 对外元信息端点前缀，同时也是摘要路由 |
| `url_prefix` | `string\|null` | `null` | 应用路由前缀，经摘要端点下发；支持完整 URL 或路径前缀，空则不下发 |
| `endpoint_middleware` | `string[]` | `[]` | 摘要端点中间件；空数组或 null 不限制 |
| `cache_ttl` | `int\|null` | `3600` | 统一缓存 TTL（秒）；`null` 不缓存，`0` 永久缓存，负值视为 `null` |
| `cache_driver` | `string\|null` | `null` | 缓存驱动；`null` 使用默认驱动 |
| `strict_mode` | `bool` | `false` | 未命中层级时抛异常（`true`）或归入 `unassigned`（`false`） |
| `scheme_version` | `int` | `1` | 摘要端点响应格式版本（`schemeVersion`） |
| `classifier` | `callable\|null` | `null` | 自定义分类回调 `fn(Route $r): ?string` |

完整配置项参考（含 `levels.{name}.*` 子键）见 [`.docs/SPEC.md` §5](./.docs/SPEC.md)。

### 开发模式

当 `APP_DEBUG=true`（Laravel 本地开发默认配置）时，Route Forge 自动跳过所有缓存读写，路由变更即时生效、无需手动清缓存。生产环境（`APP_DEBUG=false`）默认启用缓存以提升性能。

### 管理器页面

开发环境下可访问可视化路由面板 `GET /_forge/manager`，提供：

- **总览**——各层级路由数量卡片，点击快速过滤。
- **路由**——全量路由表格，支持搜索、按层级/HTTP 方法过滤、点击查看详情。
- **配置**——编辑全局设置与 `levels`，保存后直接写入 `config/forge.php`。

> ⚠️ 仅 `APP_DEBUG=true` 时注册，生产环境不存在任何管理器路由。
> 开发环境内还受 `manager_allowed_ips` IP 白名单保护（默认仅 `127.0.0.1` / `::1`
> 本机可访问；局域网调试可追加开发机局域网 IP，详见 `config/forge.php`）。

## 仓库与文档

本仓库发布 **`route-forge/laravel` composer 包**（后端）。它自 route-forge monorepo 拆分而来；前端包（`@route-forge/core`、`@route-forge/vue`、`@route-forge/react`）在另一仓库独立维护。

- [`.docs/SPEC.md`](./.docs/SPEC.md)——功能规格说明书（本仓库对应 §3 后端功能、§5 配置项、§6 错误码）。
- [`.docs/DESIGN.md`](./.docs/DESIGN.md)——设计思路与关键技术决策。
- [`llms.txt`](./llms.txt) / [`AGENTS.md`](./AGENTS.md)——机器可读概览与 Agent 集成指南。

## 开发

```bash
composer install
composer test            # 运行 PHPUnit 测试套件
composer test:coverage   # 文本覆盖率报告
```

测试基于 [orchestra/testbench](https://github.com/orchestral/testbench)，覆盖层级分配优先级（含资源路由）、中间件匹配（any/all/DNF）、端点响应、缓存、严格模式与三个 Artisan 命令。CI 通过 GitHub Actions 跑 PHP 8.2–8.5 × Laravel 11/12/13 版本矩阵（排除 PHP 8.2 × Laravel 13 组合，见 `.github/workflows/tests.yml`）。

## License

[MIT](./LICENSE)
