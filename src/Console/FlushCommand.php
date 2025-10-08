<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;

/**
 * Flush all entries from the Lada Cache database cache.
 *
 * Executes a full cache flush and reports the outcome. Compatible with Laravel 12.
 */
final class FlushCommand extends Command
{
    protected $signature = 'lada-cache:flush';

    protected $description = 'Flush all entries from the Lada Cache database cache.';

    public function handle(): int
    {
        // If disabled, do not resolve cache / Redis. Treat as no-op.
        if (! (bool) config('lada-cache.active', true)) {
            $this->info('Lada Cache is disabled. Nothing to flush.');

            return self::SUCCESS;
        }

        try {
            /** @var \Spiritix\LadaCache\Cache $cache */
            $cache = app('lada.cache');
            $cache->flush();
            $this->info('Lada Cache has been flushed successfully.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to flush Lada Cache: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
