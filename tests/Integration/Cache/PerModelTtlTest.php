<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Spiritix\LadaCache\Contracts\HasLadaTtl;
use Spiritix\LadaCache\Database\LadaCacheTrait;
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
    // LadaCacheTrait: default getLadaTtl() reads from $ladaTtl property
    // ------------------------------------------------------------------

    public function testResolveUsesTraitPropertyWhenOnlyPropertySet(): void
    {
        $resolver = new TtlResolver();

        // The model only declares `public ?int $ladaTtl = 120;`. The trait's
        // default getLadaTtl() must read the property and return its value.
        $this->assertSame(120, $resolver->resolve(new PropertyOnlyTtlFixture()));
    }

    public function testResolveNullTraitPropertyFallsBackToConfig(): void
    {
        Config::set('lada-cache.model_ttls', [PropertyNullTtlFixture::class => 3600]);
        $resolver = new TtlResolver();

        // Default `$ladaTtl = null` → trait method returns null → config fallback applies.
        $this->assertSame(3600, $resolver->resolve(new PropertyNullTtlFixture()));
    }

    public function testResolveFallsBackToConfigWhenInterfaceImplementedButPropertyMissing(): void
    {
        Config::set('lada-cache.model_ttls', [PropertyMissingTtlFixture::class => 1234]);
        $resolver = new TtlResolver();

        // implements HasLadaTtl but `$ladaTtl` is not declared at all —
        // property_exists() returns false, trait getLadaTtl() returns null,
        // resolver falls through to config.
        $this->assertSame(1234, $resolver->resolve(new PropertyMissingTtlFixture()));
    }

    public function testResolveFallsBackWhenTypedPropertyIsUninitialized(): void
    {
        Config::set('lada-cache.model_ttls', [PropertyUninitializedTtlFixture::class => 4321]);
        $resolver = new TtlResolver();

        // `public ?int $ladaTtl;` (typed, no default) — property_exists() is true
        // but reading it would throw Error("must not be accessed before initialization").
        // The trait guards with ReflectionProperty::isInitialized() and returns null,
        // letting config fallback take over.
        $this->assertSame(4321, $resolver->resolve(new PropertyUninitializedTtlFixture()));
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

class PropertyOnlyTtlFixture extends Model implements HasLadaTtl
{
    use LadaCacheTrait;

    public ?int $ladaTtl = 120;
}

class PropertyNullTtlFixture extends Model implements HasLadaTtl
{
    use LadaCacheTrait;

    // Explicit null → property_exists() true, value null → resolver falls back to config.
    public ?int $ladaTtl = null;
}

class PropertyMissingTtlFixture extends Model implements HasLadaTtl
{
    use LadaCacheTrait;
    // No $ladaTtl declared at all — covers the property_exists() === false branch.
}

class PropertyUninitializedTtlFixture extends Model implements HasLadaTtl
{
    use LadaCacheTrait;

    // Typed property without a default: property_exists() true, but reading
    // it before assignment throws an uninitialized Error. The trait's
    // ReflectionProperty::isInitialized() guard prevents the crash.
    public ?int $ladaTtl;
}
