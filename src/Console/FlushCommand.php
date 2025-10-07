<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Spiritix\LadaCache\Cache;

/**
 * Flush all entries from the Lada Cache database cache.
 *
 * Executes a full cache flush and reports the outcome. Compatible with Laravel 12.
 */
final class FlushCommand extends Command
{
    protected $signature = 'lada-cache:flush';
    protected $description = 'Flush all entries from the Lada Cache database cache.';

    public function handle(Cache $cache): int
    {
        try {
            $cache->flush();
            $this->info('Lada Cache has been flushed successfully.');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to flush Lada Cache: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
