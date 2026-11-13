<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\CacheHit;
use Spiritix\LadaCache\CacheMiss;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;

trait CacheAssertions
{
    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function seedCache(string $key, array|ReadTags $tags, array $rows): void
    {
        $lookup = $this->lookupRead($key, $tags);
        Assert::assertInstanceOf(CacheMiss::class, $lookup, "Expected no valid entry for '{$key}' before seeding");
        $this->ladaCache()->store($lookup, $rows);
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function assertCacheHas(string $key, array|ReadTags $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheHit::class,
            $this->lookupRead($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has a valid entry for '{$key}'"
        );
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function assertCacheMissing(string $key, array|ReadTags $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheMiss::class,
            $this->lookupRead($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has no valid entry for '{$key}'"
        );
    }

    /**
     * @return array{0: string, 1: ReadTags}
     */
    protected function cacheKeyAndTags(Builder $builder): array
    {
        Assert::assertInstanceOf(QueryBuilder::class, $builder);
        $reflector = new Reflector($builder);
        $tagger = new Tagger($reflector);

        return [(new Hasher($reflector))->getHash(), new ReadTags($tagger->getTags(), $tagger->cascadeReads())];
    }

    /**
     * @param  array<string>|ReadTags  $tags
     */
    protected function lookupRead(string $key, array|ReadTags $tags): CacheHit|CacheMiss
    {
        $read = $tags instanceof ReadTags ? $tags : new ReadTags(array_values($tags), []);

        return $this->ladaCache()->lookup($key, $read->tags, $read->cascadeReads);
    }

    private function ladaCache(): Cache
    {
        return app('lada.cache');
    }
}
