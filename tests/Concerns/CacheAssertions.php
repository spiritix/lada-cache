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
     * @param  array<string>  $tags
     */
    protected function seedCache(string $key, array $tags, array $rows): void
    {
        $lookup = $this->ladaCache()->lookup($key, $tags);
        Assert::assertInstanceOf(CacheMiss::class, $lookup, "Expected no valid entry for '{$key}' before seeding");
        $this->ladaCache()->store($lookup, $rows);
    }

    /**
     * @param  array<string>  $tags
     */
    protected function assertCacheHas(string $key, array $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheHit::class,
            $this->ladaCache()->lookup($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has a valid entry for '{$key}'"
        );
    }

    /**
     * @param  array<string>  $tags
     */
    protected function assertCacheMissing(string $key, array $tags, string $message = ''): void
    {
        Assert::assertInstanceOf(
            CacheMiss::class,
            $this->ladaCache()->lookup($key, $tags),
            $message !== '' ? $message : "Failed asserting that cache has no valid entry for '{$key}'"
        );
    }

    /**
     * @return array{0: string, 1: array<string>}
     */
    protected function cacheKeyAndTags(Builder $builder): array
    {
        Assert::assertInstanceOf(QueryBuilder::class, $builder);
        $reflector = new Reflector($builder);

        return [(new Hasher($reflector))->getHash(), (new Tagger($reflector))->getTags()];
    }

    private function ladaCache(): Cache
    {
        return app('lada.cache');
    }
}
