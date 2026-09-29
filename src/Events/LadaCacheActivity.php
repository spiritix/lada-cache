<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Events;

/**
 * Lada Cache activity event — dispatched on cache hit / miss / invalidate.
 *
 * Lightweight hook so listeners can collect per-table cache counters without
 * forcing every install to pay for the dispatch on the hot path.
 *
 * Activation follows `lada-cache.calibration.enabled` so unused installs incur
 * zero overhead.
 *
 * See LadaCacheServiceProvider for listener wiring. CalibrateCommand consumes
 * the resulting per-table HASH buckets through the internal reader.
 */
final class LadaCacheActivity
{
    /**
     * @param  string  $action  One of: hit, miss, invalidate.
     * @param  string  $key  Cache key (empty string for invalidate-by-tag).
     * @param  array<string>  $tags  Tags associated with the operation.
     * @param  string|null  $table  Primary table for the operation, when known.
     */
    public function __construct(
        public readonly string $action,
        public readonly string $key,
        public readonly array $tags,
        public readonly ?string $table = null,
    ) {}

    public function isHit(): bool
    {
        return $this->action === 'hit';
    }

    public function isMiss(): bool
    {
        return $this->action === 'miss';
    }
}
