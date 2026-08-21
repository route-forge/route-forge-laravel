<?php

declare(strict_types=1);

namespace RouteForge\Laravel\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use PHPUnit\Framework\TestCase;
use RouteForge\Laravel\Cache\RouteCache;

/**
 * RouteCache 单元测试。
 *
 * 重点覆盖 clear() 行为：基于 route-forge:_keys 索引清空所有层级，
 * 而非依赖 Laravel Cache 不支持的通配符语义。
 *
 * TTL 由构造函数统一传入，set() 不再从 payload 中读取 cache 字段。
 *
 * 使用 Illuminate\Cache\ArrayStore（内存驱动）避免 Redis/file 等外部依赖；
 * 不走 Laravel 容器，直接扩展 PHPUnit\Framework\TestCase。
 */
class RouteCacheTest extends TestCase
{
    private function makeCache(?int $ttl = 60): RouteCache
    {
        // 使用 ArrayStore（内存驱动）避免外部依赖
        $store = new Repository(new ArrayStore());
        return new RouteCache($store, ttl: $ttl);
    }

    public function test_set_and_get_returns_payload(): void
    {
        $cache = $this->makeCache();
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertSame($payload, $cache->get('admin'));
    }

    public function test_set_with_null_ttl_does_not_cache(): void
    {
        $cache = $this->makeCache(ttl: null);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertNull($cache->get('admin'));
    }

    public function test_set_with_zero_ttl_caches_forever(): void
    {
        $cache = $this->makeCache(ttl: 0);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertSame($payload, $cache->get('admin'));
    }

    public function test_forget_removes_single_level(): void
    {
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->forget('admin');
        $this->assertNull($cache->get('admin'));
        $this->assertNotNull($cache->get('client'));
    }

    public function test_clear_removes_all_levels(): void
    {
        $cache = $this->makeCache();
        $cache->set('public', ['level' => 'public', 'routes' => []]);
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->set('manage', ['level' => 'manage', 'routes' => []]);
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);

        $cache->clear();

        $this->assertNull($cache->get('public'));
        $this->assertNull($cache->get('client'));
        $this->assertNull($cache->get('manage'));
        $this->assertNull($cache->get('admin'));
    }

    public function test_clear_does_not_rely_on_wildcards(): void
    {
        // 验证 clear() 通过 keys-index 工作，而非依赖通配符语义。
        // ArrayStore 不支持通配符；若 clear() 用 get('route-forge:*')
        // 会返回 null 而非 keys 列表，clear() 将无效——本测试验证它确实工作。
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->clear();
        $this->assertNull($cache->get('admin'));
    }

    public function test_clear_also_clears_keys_index(): void
    {
        // 通过 public 行为间接验证：clear 后再 set 新 level 应能正常工作，
        // 且 keys 索引不残留旧数据导致 clear 后再次 clear 出错。
        $cache = $this->makeCache();
        $cache->set('admin', ['level' => 'admin', 'routes' => []]);
        $cache->clear();
        // 再写一次，验证 keys 索引不残留旧数据导致 clear 后再次 clear 出错
        $cache->set('client', ['level' => 'client', 'routes' => []]);
        $cache->clear();
        $this->assertNull($cache->get('admin'));
        $this->assertNull($cache->get('client'));
    }

    public function test_get_with_null_store_returns_null(): void
    {
        $cache = new RouteCache(null);
        $this->assertNull($cache->get('any'));
    }

    public function test_set_with_null_store_is_noop(): void
    {
        $cache = new RouteCache(null);
        $cache->set('any', ['level' => 'any', 'routes' => []]); // should not throw
        $this->assertNull($cache->get('any'));
    }

    public function test_clear_with_null_store_is_noop(): void
    {
        $cache = new RouteCache(null);
        $cache->clear(); // should not throw
        $this->assertTrue(true);
    }

    public function test_debug_mode_skips_set_and_get(): void
    {
        $store = new Repository(new ArrayStore());
        $cache = new RouteCache($store, debugMode: true, ttl: 60);
        $payload = ['level' => 'admin', 'routes' => []];
        $cache->set('admin', $payload);
        $this->assertNull($cache->get('admin'));
    }

    public function test_debug_mode_does_not_throw_on_clear(): void
    {
        $store = new Repository(new ArrayStore());
        $cache = new RouteCache($store, debugMode: true);
        $cache->clear(); // should not throw
        $this->assertTrue(true);
    }
}
