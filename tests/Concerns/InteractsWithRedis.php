<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis as RedisFacade;

trait InteractsWithRedis
{
    protected function redis(string $connection = 'cache'): Connection
    {
        return RedisFacade::connection($connection);
    }

    protected function flushRedis(string $connection = 'cache'): void
    {
        // Available on PhpRedis and Predis via magic passthrough.
        $this->redis($connection)->flushdb();
    }
}

