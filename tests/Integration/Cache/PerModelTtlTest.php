<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Spiritix\LadaCache\Contracts\HasLadaTtl;
use Spiritix\LadaCache\Redis as LadaRedis;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\TtlResolver;

class PerModelTtlTest extends TestCase
{
    // ------------------------------------------------------------------
    // TtlResolver: fallback chain
    // ------------------------------------------------------------------

    public function testResolveReturnsNullForNullModel(): void
    {
        $resolver = new TtlResolver();

        $this->assertNull($resolver->resolve(null));
    }

    public function testResolveReturnsNullWhenNoOverridePresent(): void
    {
        Config::set('lada-cache.model_ttls', []);
        $resolver = new TtlResolver();

        $this->assertNull($resolver->resolve(new PlainFixture()));
    }

    public function testResolveUsesConfigModelTtlsWhenNoInterface(): void
    {
        Config::set('lada-cache.model_ttls', [PlainFixture::class => 7200]);
        $resolver = new TtlResolver();

        $this->assertSame(7200, $resolver->resolve(new PlainFixture()));
    }

    public function testResolvePrefersInterfaceOverConfig(): void
    {
        // Fixture returns 60; config says 9999 — interface wins
        Config::set('lada-cache.model_ttls', [HasTtlFixture::class => 9999]);
        $resolver = new TtlResolver();

        $this->assertSame(60, $resolver->resolve(new HasTtlFixture()));
    }

    public function testResolveFallsBackToConfigWhenInterfaceReturnsNull(): void
    {
        Config::set('lada-cache.model_ttls', [HasTtlNullFixture::class => 1800]);
        $resolver = new TtlResolver();

        $this->assertSame(1800, $resolver->resolve(new HasTtlNullFixture()));
    }

    public function testResolveReturnsZeroWhenInterfaceExplicitlyReturnsZero(): void
    {
        // 0 = "persist forever" — distinct from null = defer to fallback
        $resolver = new TtlResolver();

        $this->assertSame(0, $resolver->resolve(new HasTtlZeroFixture()));
    }

    // ------------------------------------------------------------------
    // Cache::set: TTL semantics with per-model values
    // ------------------------------------------------------------------

    public function testCacheSetUsesExplicitTtlWhenProvided(): void
    {
        $cache = app('lada.cache');
        $redis = new LadaRedis();

        $cache->set('explicit-ttl-key', ['t:tag'], ['data' => 1], 120);

        $ttl = (int) $redis->ttl($redis->prefix('explicit-ttl-key'));

        // Allow ±5s drift; should be ~120
        $this->assertGreaterThan(115, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    public function testCacheSetZeroTtlPersistsForever(): void
    {
        $cache = app('lada.cache');
        $redis = new LadaRedis();

        $cache->set('zero-ttl-key', ['t:tag'], ['data' => 1], 0);

        // Redis TTL = -1 means key exists with no expiration
        $this->assertSame(-1, (int) $redis->ttl($redis->prefix('zero-ttl-key')));
    }

    public function testCacheSetNullTtlFallsBackToGlobal(): void
    {
        Config::set('lada-cache.expiration_time', 300);
        // Re-resolve because Cache reads expiration_time at construction
        app()->forgetInstance('lada.cache');

        $cache = app('lada.cache');
        $redis = new LadaRedis();

        $cache->set('null-ttl-key', ['t:tag'], ['data' => 1], null);

        $ttl = (int) $redis->ttl($redis->prefix('null-ttl-key'));
        $this->assertGreaterThan(295, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }
}

// ------------------------------------------------------------------
// Minimal fixtures — Eloquent models implementing HasLadaTtl
// ------------------------------------------------------------------

class PlainFixture extends Model
{
}

class HasTtlFixture extends Model implements HasLadaTtl
{
    public function getLadaTtl(): ?int
    {
        return 60;
    }
}

class HasTtlNullFixture extends Model implements HasLadaTtl
{
    public function getLadaTtl(): ?int
    {
        return null;
    }
}

class HasTtlZeroFixture extends Model implements HasLadaTtl
{
    public function getLadaTtl(): ?int
    {
        return 0;
    }
}
