<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Spiritix\LadaCache\Cache;
use Spiritix\LadaCache\Encoder;
use Spiritix\LadaCache\Redis;
use Throwable;

final class EnableCommand extends Command
{
    protected $signature = 'lada-cache:enable';

    protected $description = 'Enable the Lada Cache database cache.';

    public function handle(): int
    {
        if (! $this->writeConfig('active', true)) {
            $this->error('Failed to enable Lada Cache.');

            return self::FAILURE;
        }

        $this->info('Lada Cache enabled.');
        $this->line('Note: if you have LADA_CACHE_ACTIVE=false in your .env, this setting will be ignored.');

        try {
            (new Cache(new Redis, new Encoder))->retireCurrentGeneration();
        } catch (Throwable $exception) {
            $this->warn('Could not retire results cached before Lada Cache was disabled, flush them with lada-cache:flush: '.$exception->getMessage());
        }

        return self::SUCCESS;
    }
}
