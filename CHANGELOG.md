# Changelog

本项目所有重要变更都会记录在此文件中。

格式基于 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [1.2.2] - 2026-08-30

### Added

- 新增配置项 `manager_allowed_ips`：管理器页面 IP 白名单。默认仅本机回环
  （`127.0.0.1` / `::1`）可访问；支持精确 IP 列表、`'*'` 放行任意来源、
  `null`/空数组表示显式不限制。管理器路由仅 `APP_DEBUG=true` 时注册，
  线上本配置天然不生效
- 管理器保存配置时，`endpoint_middleware` 与 `manager_allowed_ips` 等
  不在表单中编辑的配置项原样透传，不再丢失

### Fixed

- **严格模式不可用**：包自身端点路由（`forge.routes.*` / `forge.manager.*`）
  此前参与元信息端点扫描——它们永远不带 tier，`strict_mode=true` 时必然抛
  `RF_BE_001` 导致摘要/层级端点全面 500；非严格模式下还会泄露进 `unassigned`
  明细被前端当作用户路由消费。现所有扫描统一排除包自身与框架内部路由
  （含 Laravel 12+ 的 `storage.*`），与 `route:forge:list` /
  `route:forge:types` / 管理器页面的过滤口径一致
- 层级下无路由时 `routes` 序列化为 `[]` 而非 `{}`，与「按路由名索引的对象」
  契约不一致的问题
- 配置了 `classifier` 回调时，管理器保存配置会静默抹掉该闭包；
  现改为拒绝保存并返回 422 明确提示

### Changed

- 仓库地址迁移至 GitHub 组织 `route-forge`：`composer.json`
  homepage/support 与 README 链接更新为 `route-forge/route-forge`
- 路线图移除 v1.3 Vite 插件（属前端工具链，不在后端包范围）
- 文档同步兼容矩阵描述：CI 覆盖 PHP 8.2–8.5 × Laravel 11/12/13
  （排除 PHP 8.2 × Laravel 13 组合）

## [1.2.1] - 2026-08-29

### Fixed

- 修复 CI 矩阵全红的三类问题：矩阵排除 PHP 8.2 × Laravel 13 组合、
  修正 Laravel 13 分支的 testbench 版本、放行 Laravel 11 依赖安装
- 兼容 Laravel 12 的框架自带 `storage.*` 路由与 Route 门面缓存
- `ForgeRouter::__call` 兼容 PHP 8.2/8.3 的 new 链式调用语法

## [1.2.0] - 2026-08-28

### Changed

- `classifier` 支持任意 callable（函数名字符串、`[Class, 'method']` 数组、
  可调用对象），此前非 Closure callable 会被静默丢弃
- 清理遗留问题，修正 SPEC 悬空/错误章节交叉引用
- 测试补齐：15 个边界与端到端用例、管理器页面零覆盖用例

## [1.1.1] - 2026-08-25

### Changed

- 更新文档说明

## [1.1.0] - 2026-08-25

### Added

- 开发环境可视化路由管理器页面（`GET /_forge/manager`）：Blade + 原生
  CSS/JS 零前端构建依赖，含总览（层级卡片）、路由（搜索/过滤/详情弹窗）、
  配置（全局设置表单 + levels JSON 编辑器）三个标签页，仅 `APP_DEBUG=true`
  时注册，配置保存直接写入 `config/forge.php` 并自动清除配置编译缓存

## [1.0.2] - 2026-08-24

### Fixed

- 修复 `RouteCache` 三处缺陷（缓存键索引维护相关）

## [1.0.1] - 2026-08-24

### Changed

- 摘要端点响应结构迭代为 `schemeVersion: 1`

## [1.0.0] - 2026-08-23

### Added

- 首个发布：路由分级懒加载后端（层级分配五级优先级、元信息端点、
  摘要端点、统一缓存、`route:forge:list` / `route:forge:types` /
  `route:forge:clear` Artisan 命令）
