# Copilot instructions — route-forge/laravel

Package `route-forge/laravel` (`RouteForge\Laravel\`) exposes Laravel named routes to a SPA via per-tier HTTP metadata endpoints and generates TypeScript types. It is the backend of the route-forge project; frontend SDKs (`@route-forge/*`) are in a separate repo.

When generating or editing code that uses this package:

- Assign tiers with `->tier('name')`, `Route::group(['tier' => 'name', ...], fn)`, `Route::tier('name')->group(fn)`, or `config/forge.php` match rules. Never assume fixed tier names — levels are user-defined.
- **Group attributes go *before* `group()`.** `Route::group([...], fn)->tier('x')` does not apply. Use the array form or `Route::tier('x')->group(fn)`.
- When **renaming a named route**, keep SPA callers working by declaring the old name as an alias: `->forgeAlias('old.name')` on the renamed route (explicit, wins over config) or batch in `config/forge.php` via `'aliases' => ['old.name' => 'new.name']`. Aliases appear as extra keys in the target's level metadata and in generated types — the frontend needs zero changes. Clean aliases up once the rename settles (`php artisan route:forge:list --aliases`); a dangling alias throws `AliasTargetException` (RF_BE_008).
- Read routes from the endpoints, don't hardcode URLs: summary `GET /_forge/routes`, per-tier `GET /_forge/routes/{level}`; `unassigned` is a real tier.
- Generate frontend types with `php artisan route:forge:types` (reads the route registry offline).
- Respect backend semantics: `strict_mode=true` throws `RouteTierNotAssignedException` on a miss; `false` routes to `unassigned`. Frontend validation always throws — no silent ignores.
- Cache is auto-bypassed when `APP_DEBUG=true`; `APP_DEBUG=false` in production. The manager page exists only in dev.

When editing this repo:

- PHP `^8.2`, Laravel `^11|^12|^13`, tests via PHPUnit + orchestra/testbench (`composer test`). The full suite must pass before committing; add tests for new behavior.
- `.docs/SPEC.md` and `.docs/DESIGN.md` are the source of truth for the front/back contract — follow them and keep them updated.
- Commit format: `type(scope): 中文描述`. Do not push without being asked.

See `AGENTS.md` and `llms.txt` for the fuller integration guide.
