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
        return array_map(
            static fn (CommandExecuted $event): string => strtolower($event->command),
            $this->recordRedisCommandEvents($callback),
        );
    }

    /**
     * @return list<CommandExecuted>
     */
    protected function recordRedisCommandEvents(callable $callback): array
    {
        $events = [];
        $connection = RedisFacade::connection((string) config('lada-cache.redis_connection'));
        $connection->setEventDispatcher(app('events'));
        app('events')->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$events): void {
            $events[] = $event;
        });

        try {
            $callback();
        } finally {
            $connection->unsetEventDispatcher();
        }

        return $events;
    }
}
