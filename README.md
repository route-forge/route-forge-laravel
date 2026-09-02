# Route Forge for Laravel

**Use your Laravel named routes in a Vue / React / Inertia SPA — without hardcoding URLs and without shipping the whole route table to the client.**

Route Forge exposes Laravel's named routes through a small HTTP metadata endpoint, lets you **split routes into tiers and lazy-load them on demand**, and **generates TypeScript types** so route names and parameters are type-safe on the frontend. It works with zero annotations — it reads directly from Laravel's own route registry.

[![Latest Version on Packagist](https://img.shields.io/packagist/v/route-forge/laravel.svg?style=flat-square)](https://packagist.org/packages/route-forge/laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/route-forge/laravel.svg?style=flat-square)](https://packagist.org/packages/route-forge/laravel)
[![PHP](https://img.shields.io/packagist/php-v/route-forge/laravel.svg?style=flat-square)](#requirements)
[![Laravel](https://img.shields.io/badge/Laravel-11%20|%2012%20|%2013-red.svg?style=flat-square)](#requirements)
[![Tests](https://img.shields.io/github/actions/workflow/status/route-forge/route-forge-laravel/tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/route-forge/route-forge-laravel/actions)
[![License](https://img.shields.io/github/license/route-forge/route-forge-laravel.svg?style=flat-square)](./LICENSE)

**语言 / Language:** [English](./README.md) · [简体中文](./README_zh.md)

> **For AI assistants / coding agents:** this package is [`route-forge/laravel`](https://packagist.org/packages/route-forge/laravel). A machine-readable overview is available at [`llms.txt`](./llms.txt), and integration guidance for agents is in [`AGENTS.md`](./AGENTS.md).

## What problem it solves

When a Laravel app is fronted by a SPA (Vue / React / Inertia / a standalone mobile web app), the frontend needs to build URLs to backend endpoints. The usual options all hurt:

- **Hardcoding URLs in the frontend** duplicates routing knowledge, drifts from the backend, and is easy to get wrong.
- **Injecting the whole route table** (e.g. as a JS global) grows with the app and ships routes the current user can never reach.
- **Hand-writing an API client per endpoint** reinvents the wheel in every project and has no type safety.

Route Forge makes the backend route table the **single source of truth**: the frontend asks for what it needs, per tier, at runtime — and gets TypeScript types generated straight from the authoritative route registry.

## Why Route Forge (differentiators)

- **Tiered lazy loading** — tag routes with `->tier()`, `Route::group(['tier' => ...])`, `Route::tier(...)->group(...)`, config match rules, or a `classifier` callback; the frontend loads only the tier it currently needs instead of the entire route map.
- **Auto-discovery summary endpoint** — `GET /_forge/routes` returns every tier overview, global config, and unassigned routes, so a fresh client can bootstrap itself with no hardcoded config.
- **TypeScript type generation** — `php artisan route:forge:types` emits `.d.ts` from the real route registry (works offline, no HTTP server, CI-friendly).
- **Backend-authoritative config** — `strict_mode`, URL prefix, and per-tier load strategy are owned by the backend and delivered to clients through the summary endpoint, so frontend and backend never disagree.
- **Zero annotations, zero intrusion** — built purely on Laravel extension points (macros + ServiceProvider); it reads `Route::getRoutes()` and never modifies the framework.
- **Caching & dev ergonomics** — one shared TTL/cache driver, automatic cache bypass under `APP_DEBUG`, plus a dev-only visual route manager.

> **Full-stack pairing:** this repository is the **backend** half of the route-forge project. Pair it with the companion frontend SDK [`@route-forge/core`](https://www.npmjs.com/package/@route-forge/core) (plus `@route-forge/vue` / `@route-forge/react`) for request wrapping, concurrency control, and auth-state-aware tier loading.

## Feature overview

Route Forge offers several interchangeable, combinable ways to assign tiers. Level names are entirely yours — the package ships no fixed tiers.

1. **`->tier()` macro** — mark a route explicitly; chainable and works on resource routes too.
2. **`tier` option on `Route::group`** — the whole group inherits a tier; nested groups override the parent.
3. **Fluent `Route::tier(...)->group(...)`** — a streaming form of (2) that composes with `middleware` / `prefix` / `as` in any order.
4. **Config-driven batch assignment** — `config/forge.php` classifies routes by URI prefix / middleware (`any` / `all` / DNF array matching).

Plus:

- **Five-level priority**: explicit `->tier()` > group pass-through > `classifier` callback > config match > `unassigned` fallback.
- **Metadata endpoint** `GET /_forge/routes/{level}` — per-tier route metadata (name + URI + method + params) for lazy loading.
- **Summary endpoint** `GET /_forge/routes` — all-tier overview + global config + unassigned info for client auto-discovery.
- **Unified caching** — shared TTL and `cache_driver` across all endpoints; automatically skipped in dev (`APP_DEBUG=true`).
- **Artisan commands** — `route:forge:list`, `route:forge:types`, `route:forge:clear`.
- **Strict mode** — `strict_mode=true` throws `RouteTierNotAssignedException` on a miss; otherwise routes fall into `unassigned`.
- **Manager page** — a dev-only visual panel at `GET /_forge/manager` (overview, search/filter, config editing).

## Requirements

- PHP `^8.2`
- Laravel (illuminate) `^11.0 || ^12.0 || ^13.0`

## Install

```bash
composer require route-forge/laravel

# Publish the config file (optional — defaults work out of the box)
php artisan vendor:publish --tag=forge-config
```

The service provider is auto-registered via Laravel package discovery.

## Quickstart

### Assign tiers

```php
// 1) Explicit marker
Route::post('/auth/login', [AuthController::class, 'login'])
    ->name('auth.login')
    ->tier('public');

// 2) Group inheritance (array syntax)
Route::group([
    'prefix'     => 'admin',
    'middleware' => ['auth', 'admin'],
    'tier'       => 'admin',
], function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// 3) Fluent chain (tier composes with any route attribute, in any order)
Route::middleware(['auth', 'admin'])->tier('admin')->prefix('admin')->group(function () {
    Route::get('/users', [AdminUserController::class, 'index'])
         ->name('admin.users.index');
});

// 4) Config-driven batch match (config/forge.php)
// 'admin' => [
//     'match' => ['prefix' => ['admin'], 'middleware' => ['auth', 'admin']],
//     'load'  => 'lazy',
// ],
```

> ⚠️ **Group attributes must be declared *before* `group()`.**
> `Route::group([...], fn)->tier('admin')` does **not** apply the tier — Laravel's `group()`
> finishes registering child routes and pops the group stack before returning, so a trailing
> chained call cannot reach them. Use either the array syntax `Route::group(['tier' => 'admin', ...], fn)`
> or the leading fluent form `Route::tier('admin')->group(fn)`.

### Fetch metadata from the frontend

```
GET /_forge/routes              # Summary: all tiers (incl. the special `unassigned` tier) + global config + schemeVersion
GET /_forge/routes/admin        # Metadata for every named route in the `admin` tier
GET /_forge/routes/unassigned   # Metadata for routes that matched no tier
```

```js
// Bootstrap: discover available tiers
const summary = await fetch('/_forge/routes').then(r => r.json());
// On demand: load only the tier you need
const adminRoutes = await fetch('/_forge/routes/admin').then(r => r.json());
```

### Optional: embed the summary in the first page

If your frontend HTML is server-rendered by Laravel (Blade), you can skip the first summary round-trip entirely. Drop `@forgeSummary` in the `<head>` (before your JS bundle) and it inlines the **summary endpoint's** payload as a one-time, self-deleting, non-enumerable `window.__ROUTE_FORGE__` accessor that `@route-forge/core` reads once at init:

```blade
<head>
    {{-- ... --}}
    @forgeSummary
</head>
```

It is purely an accelerator layered on top of the endpoints:

- Embeds **only the summary** — per-tier route tables still lazy-load over `GET /_forge/routes/{level}` (protected tiers never get inlined into public HTML).
- Reuses the same producer/cache as the summary endpoint (byte-for-byte identical), adds **no new HTTP endpoint**, and does **not** bump `schemeVersion`.
- Pure SPA / Vite-dev setups simply don't use the directive and fall back to the network summary — behavior unchanged.

> The one-time self-deleting accessor only shrinks the data's runtime footprint on `window`; the summary is still visible in the HTML source. It is **not** an XSS- or sniffing-proof boundary — do not treat it as a security mechanism.

### Artisan commands

```bash
# Show tier assignment (--level to filter, --json, --unassigned)
php artisan route:forge:list

# Emit TypeScript declarations (stdout by default; --out file; --level / --json)
php artisan route:forge:types

# Clear route metadata cache (--level for one tier; omit for all).
# Laravel's built-in `php artisan route:clear` also clears this automatically.
php artisan route:forge:clear
```

## Configuration (`config/forge.php`)

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `levels` | `array` | see config file | Tier definition table (match rules, load strategy) |
| `endpoint_prefix` | `string` | `'/_forge/routes'` | Public metadata endpoint prefix (also the summary route) |
| `url_prefix` | `string\|null` | `null` | App route prefix delivered via the summary endpoint; absolute URL or path prefix; empty = not delivered |
| `endpoint_middleware` | `string[]` | `[]` | Middleware on the summary endpoint; empty/null = unrestricted |
| `cache_ttl` | `int\|null` | `3600` | Shared cache TTL (seconds); `null` = no cache, `0` = forever, negative treated as `null` |
| `cache_driver` | `string\|null` | `null` | Cache driver; `null` uses the default |
| `strict_mode` | `bool` | `false` | Throw on a tier miss (`true`) or fall into `unassigned` (`false`) |
| `scheme_version` | `int` | `1` | Summary response format version (`schemeVersion`) |
| `classifier` | `callable\|null` | `null` | Custom classifier `fn(Route $r): ?string` |

Full reference (including `levels.{name}.*` sub-keys) is in [`.docs/SPEC.md` §5](./.docs/SPEC.md).

### Development mode

When `APP_DEBUG=true` (Laravel's default for local dev), Route Forge skips all cache reads/writes so route changes take effect immediately — no manual cache clearing. In production (`APP_DEBUG=false`), caching is enabled for performance.

### Manager page

In development, a visual route panel is available at `GET /_forge/manager`:

- **Overview** — per-tier route counts, click to filter.
- **Routes** — full route table with search, tier/method filters, and detail view.
- **Config** — edit global settings and `levels`, saving directly writes `config/forge.php`.

> ⚠️ Only registered when `APP_DEBUG=true`; no manager routes exist in production.
> Even in dev it is guarded by the `manager_allowed_ips` allowlist (default: `127.0.0.1` / `::1`
> only; append your LAN IP for device testing — see `config/forge.php`).

## Repository & docs

This repository ships the **`route-forge/laravel` composer package** (the backend). It was split out from the route-forge monorepo; the frontend packages (`@route-forge/core`, `@route-forge/vue`, `@route-forge/react`) are maintained separately.

- [`.docs/SPEC.md`](./.docs/SPEC.md) — functional specification (this repo covers §3 backend features, §5 config, §6 error codes).
- [`.docs/DESIGN.md`](./.docs/DESIGN.md) — design rationale and key technical decisions.
- [`llms.txt`](./llms.txt) / [`AGENTS.md`](./AGENTS.md) — machine-readable overview and agent integration guide.

## Development

```bash
composer install
composer test            # run the PHPUnit suite
composer test:coverage   # text coverage report
```

Tests run on [orchestra/testbench](https://github.com/orchestral/testbench) and cover tier-assignment priority (incl. resource routes), middleware matching (any/all/DNF), endpoint responses, caching, strict mode, and the three Artisan commands. CI runs a PHP 8.2–8.5 × Laravel 11/12/13 matrix on GitHub Actions (excluding PHP 8.2 × Laravel 13; see `.github/workflows/tests.yml`).

## License

[MIT](./LICENSE)
