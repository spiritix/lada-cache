<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

/**
 * Disable the Lada Cache database cache.
 *
 * This command updates the package configuration to set `active` to false
 * and reports the outcome via the console. Compatible with Laravel 12.
 */
final class DisableCommand extends Command
{
    protected $signature = 'lada-cache:disable';

    protected $description = 'Disable the Lada Cache database cache.';

    public function handle(): int
    {
        if ($this->writeConfig('active', false)) {
            $this->info('Lada Cache disabled.');

            return self::SUCCESS;
        }

        $this->error('Failed to disable Lada Cache.');

        return self::FAILURE;
    }
}
