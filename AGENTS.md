# AGENTS.md — Working with `route-forge/laravel`

Guidance for AI coding agents (Cursor, GitHub Copilot, Claude Code, Codex, …) integrating **Route Forge** into a Laravel backend, and for editing this package.

## What this package is

`route-forge/laravel` (namespace `RouteForge\Laravel\`) is the **backend** of the route-forge project. It exposes Laravel named routes to a SPA via per-tier HTTP metadata endpoints and generates TypeScript types. Companion frontend SDKs (`@route-forge/core`, `@route-forge/vue`, `@route-forge/react`) live in a separate repository.

- Language / runtime: PHP `^8.2`
- Framework: illuminate `^11 || ^12 || ^13`
- Package manager: **Composer** (this repo); the frontend monorepo uses pnpm — do not mix.

## Integrating it into a Laravel app

1. `composer require route-forge/laravel` — the `ForgeServiceProvider` is auto-registered.
2. Optionally `php artisan vendor:publish --tag=forge-config` to get `config/forge.php`. Defaults work with zero config.
3. Assign tiers to your named routes (see below). Anything that matches nothing lands in the special `unassigned` tier (or throws in strict mode).
4. Point the frontend at `GET /_forge/routes` (summary) and `GET /_forge/routes/{level}` (per tier).

### Correct ways to tag tiers

```php
// Explicit (also works on resource routes)
Route::post('/auth/login', [AuthController::class, 'login'])->name('auth.login')->tier('public');

// Group (array syntax)
Route::group(['prefix' => 'admin', 'middleware' => ['auth', 'admin'], 'tier' => 'admin'], function () {
    Route::get('/users', [AdminUserController::class, 'index'])->name('admin.users.index');
});

// Group (fluent) — tier is just another chainable attribute
Route::middleware(['auth', 'admin'])->tier('admin')->prefix('admin')->group(function () { /* ... */ });

// Batch rules in config/forge.php
// 'admin' => ['match' => ['prefix' => ['admin'], 'middleware' => ['auth', 'admin']], 'load' => 'lazy'],
```

Priority: explicit `->tier()` > group pass-through > `classifier` callback > config match > `unassigned`.

### The one rule agents get wrong

> Group attributes must be declared **before** `group()`. `Route::group([...], fn)->tier('admin')` silently does NOT apply, because `group()` registers children and pops the group stack before returning. Use the array form or `Route::tier('admin')->group(fn)`.

## Endpoints (do not hardcode; discover)

- `GET /_forge/routes` → summary: tier list, global config, `schemeVersion`, unassigned info.
- `GET /_forge/routes/{level}` → named-route metadata for that tier: `name`, `uri`, `method`, `params`.
- The special level `unassigned` holds routes that matched no tier.

## Generating frontend types

```bash
php artisan route:forge:types --out resources/js/route-forge.d.ts
```

Run it in the PHP/CI build stage — it reads the in-memory route registry, so it needs no running HTTP server.

## Useful commands

- `php artisan route:forge:list [--level=...] [--json] [--unassigned]`
- `php artisan route:forge:clear [--level=...]` (also auto-cleared by `route:clear`)

## Config keys (`config/forge.php`)

`levels`, `endpoint_prefix`, `url_prefix`, `endpoint_middleware`, `cache_ttl`, `cache_driver`, `strict_mode`, `scheme_version`, `classifier`. See [`.docs/SPEC.md` §5](./.docs/SPEC.md) for the full reference including `levels.{name}.*`.

## Behavior constraints to preserve

- **Dev (`APP_DEBUG=true`)**: cache reads/writes are bypassed automatically; route edits apply instantly.
- **Manager page** (`GET /_forge/manager`): only registered when `APP_DEBUG=true`; also gated by the `manager_allowed_ips` allowlist (default `127.0.0.1` / `::1`). Never expose it in production.
- **Strict mode**: `strict_mode=true` throws `RouteTierNotAssignedException` on an unmatched level; `false` routes them to `unassigned`.
- **Frontend validation always throws** — no silent ignore. `strict_mode` is a backend concept (where an unmatched route goes); the deprecated frontend `strict` flag is unrelated — do not reintroduce it.
- **Exclude the package's own routes** (`forge.routes.*`, `forge.manager.*`) from every metadata scan. If they leak into a scan, `strict_mode` will 500.

## Editing this package (repo conventions)

- Structure: `src/` (Http controllers/middleware, Console commands, Cache, Exceptions, `TierResolver`, `RouteRepository`, registrars, `ForgeServiceProvider`), `config/forge.php`, `resources/` (manager views), `tests/` (PHPUnit + orchestra/testbench), `.docs/` (SPEC + DESIGN as the single source of truth for the front/back contract).
- Tests: `composer test` (or `vendor/bin/phpunit`). Full suite must pass before any commit. New features/fixes ship with matching tests.
- Commit messages: `type(scope): 中文描述` (type ∈ feat/fix/test/docs/refactor/chore). Do **not** push without explicit instruction.
- Design decisions and the front/back contract are documented in [`.docs/DESIGN.md`](./.docs/DESIGN.md) and [`.docs/SPEC.md`](./.docs/SPEC.md) — consult them before proposing changes, and keep them in sync when behavior changes.
