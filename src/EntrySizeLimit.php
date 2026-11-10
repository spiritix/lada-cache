<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use InvalidArgumentException;
use Spiritix\LadaCache\Support\ConfigValue;

/**
 * Results larger than this are returned but not cached: storing and fetching them would block
 * the single-threaded Redis and move as much data as querying the database again.
 */
final readonly class EntrySizeLimit
{
    public function __construct(public int $bytes)
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException(sprintf('Invalid lada-cache.max_entry_size %d, expected 0 for no limit or more bytes', $bytes));
        }
    }

    public static function fromConfig(): self
    {
        return new self(ConfigValue::integer('lada-cache.max_entry_size', 8 * 1024 * 1024));
    }

    public function allows(string $payload): bool
    {
        return $this->bytes === 0 || strlen($payload) <= $this->bytes;
    }
}
