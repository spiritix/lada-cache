<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Services;

/**
 * Flush all entries from the Lada Cache database cache.
 *
 * Executes a full cache flush and reports the outcome.
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
            $cache = Services::cache();
            $started = hrtime(true);
            $deleted = $cache->flush();
            $this->info('Lada Cache has been flushed successfully.');
            $this->line(sprintf('Deleted %d retired keys in %.2f seconds.', $deleted, (hrtime(true) - $started) / 1e9));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to flush Lada Cache: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
