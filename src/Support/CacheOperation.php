<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Support;

enum CacheOperation: string
{
    case Analyze = 'analyzing the query';
    case Read = 'reading from the cache';
    case Store = 'storing in the cache';
    case Invalidate = 'invalidating the cache';
    case Retire = 'retiring the cache generation';
    case Prune = 'pruning retired cache entries';
    case Replay = 'replaying pending invalidations';

    public function compromisesFreshness(): bool
    {
        return match ($this) {
            self::Invalidate, self::Retire => true,
            self::Analyze, self::Read, self::Store, self::Prune, self::Replay => false,
        };
    }
}
