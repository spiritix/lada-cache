<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Redis as RedisFacade;

trait RecordsRedisCommands
{
    /**
     * @return list<string>
     */
    protected function recordRedisCommands(callable $callback): array
    {
        $commands = [];
        $connection = RedisFacade::connection((string) config('lada-cache.redis_connection'));
        $connection->setEventDispatcher(app('events'));
        app('events')->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
            $commands[] = strtolower($event->command);
        });

        try {
            $callback();
        } finally {
            $connection->unsetEventDispatcher();
        }

        return $commands;
    }
}
