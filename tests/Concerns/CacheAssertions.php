<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Redis as LadaRedis;

trait CacheAssertions
{
    protected function assertCacheHas(string $key, string $message = ''): void
    {
        $redis = new LadaRedis();
        $exists = (bool) $redis->exists($redis->prefix($key));
        Assert::assertTrue($exists, $message !== '' ? $message : "Failed asserting that cache has key '{$key}'");
    }

    protected function assertCacheMissing(string $key, string $message = ''): void
    {
        $redis = new LadaRedis();
        $exists = (bool) $redis->exists($redis->prefix($key));
        Assert::assertFalse($exists, $message !== '' ? $message : "Failed asserting that cache is missing key '{$key}'");
    }

    protected function assertTagHasKey(string $tag, string $key, string $message = ''): void
    {
        $redis = new LadaRedis();
        $isMember = (bool) $redis->sismember($redis->prefix($tag), $redis->prefix($key));
        Assert::assertTrue($isMember, $message !== '' ? $message : "Failed asserting that tag '{$tag}' contains key '{$key}'");
    }
}

