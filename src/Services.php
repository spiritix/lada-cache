<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Support\Facades\App;
use LogicException;
use Spiritix\LadaCache\Debug\CacheCollector;

final class Services
{
    public const string REDIS = 'lada.redis';

    public const string CACHE = 'lada.cache';

    public const string HANDLER = 'lada.handler';

    public const string COLLECTOR = 'lada.collector';

    public static function redis(): Redis
    {
        return self::make(self::REDIS, Redis::class);
    }

    public static function cache(): Cache
    {
        return self::make(self::CACHE, Cache::class);
    }

    public static function handler(): QueryHandler
    {
        return self::make(self::HANDLER, QueryHandler::class);
    }

    public static function collector(): ?CacheCollector
    {
        return App::bound(self::COLLECTOR) ? self::make(self::COLLECTOR, CacheCollector::class) : null;
    }

    /**
     * @template TService of object
     *
     * @param  class-string<TService>  $class
     * @return TService
     */
    public static function make(string $id, string $class): object
    {
        $service = App::make($id);

        return $service instanceof $class ? $service : throw new LogicException(sprintf('Container binding %s resolved to %s, expected %s', $id, get_debug_type($service), $class));
    }
}
