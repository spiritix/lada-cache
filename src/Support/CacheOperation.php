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
    case ReadDependents = 'reading the tables the database changes along with a write';
    case ReadTrigger = 'reading which tables a trigger writes';

    public function compromisesFreshness(): bool
    {
        return match ($this) {
            self::Invalidate, self::Retire => true,
            self::Analyze, self::Read, self::Store, self::Prune, self::Replay, self::ReadDependents, self::ReadTrigger => false,
        };
    }

    /**
     * What a failure of the operation leads to, as the log states it.
     */
    public function consequenceOfFailure(): string
    {
        return match ($this) {
            self::Invalidate, self::Retire => 'cached results may be stale until they expire',
            self::Analyze, self::Read, self::Store, self::Prune, self::Replay => 'continuing without the cache',
            self::ReadDependents => 'every write retires the whole cache until it is read again in '.DependentTables::UNREADABLE_SECONDS.' seconds',
            self::ReadTrigger => 'every write firing it retires the whole cache',
        };
    }
}
