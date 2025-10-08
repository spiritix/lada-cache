<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

/**
 * Enable the Lada Cache database cache.
 *
 * This command updates the package configuration to set `active` to true
 * and reports the outcome via the console.
 */
final class EnableCommand extends Command
{
    protected $signature = 'lada-cache:enable';

    protected $description = 'Enable the Lada Cache database cache.';

    public function handle(): int
    {
        if ($this->writeConfig('active', true)) {
            $this->info('Lada Cache enabled.');
            $this->line('Note: if you have LADA_CACHE_ACTIVE=false in your .env, this setting will be ignored.');

            return self::SUCCESS;
        }

        $this->error('Failed to enable Lada Cache.');

        return self::FAILURE;
    }
}
