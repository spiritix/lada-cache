<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Throwable;

/**
 * Enable the Lada Cache database cache.
 *
 * Writes made while Lada Cache was disabled did not invalidate anything, so
 * results cached before are flushed first. When that fails, the cache stays
 * disabled. Otherwise the package configuration is updated to set `active`
 * to true and the outcome is reported via the console.
 */
final class EnableCommand extends Command
{
    protected $signature = 'lada-cache:enable';

    protected $description = 'Enable the Lada Cache database cache.';

    public function handle(): int
    {
        try {
            // The singletons are not registered while Lada Cache is disabled.
            (new Cache(new Redis, new Encoder))->flush();
        } catch (Throwable $e) {
            $this->error('Lada Cache was not enabled: could not flush results cached before it was disabled: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->writeConfig('active', true)) {
            $this->info('Lada Cache enabled.');
            $this->line('Note: if you have LADA_CACHE_ACTIVE=false in your .env, this setting will be ignored.');

            return self::SUCCESS;
        }

        $this->error('Failed to enable Lada Cache.');

        return self::FAILURE;
    }
}
