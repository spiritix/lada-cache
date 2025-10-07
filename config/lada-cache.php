<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Enable or Disable Lada Cache
    |--------------------------------------------------------------------------
    |
    | By setting this value to false, Lada Cache will be completely disabled.
    | This can be useful during debugging, development, or when temporarily
    | troubleshooting cache-related behavior.
    |
    */
    'active' => env('LADA_CACHE_ACTIVE', true),

    /*
    |--------------------------------------------------------------------------
    | Cache Driver
    |--------------------------------------------------------------------------
    |
    | The cache driver that should be used for storing Lada Cache entries.
    | By default, Redis is used since it provides excellent performance for
    | tagged and granular cache invalidation.
    |
    */
    'driver' => env('LADA_CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection Name
    |--------------------------------------------------------------------------
    |
    | Choose which Redis connection (as defined in config/database.php -> redis)
    | Lada Cache should use. This allows isolating Lada Cache from your default
    | Redis connection. Typically you can set this to 'cache' or define a
    | dedicated 'lada-cache' connection.
    |
    */
    'redis_connection' => env('LADA_CACHE_REDIS_CONNECTION', 'cache'),

    /*
    |--------------------------------------------------------------------------
    | Redis Prefix
    |--------------------------------------------------------------------------
    |
    | This prefix will be prepended to all cache keys stored in Redis.
    | It helps to isolate data between different environments or projects.
    | Do not change this value in production, as doing so will make all
    | previously cached entries inaccessible.
    |
    */
    'prefix' => env('LADA_CACHE_PREFIX', 'lada:'),

    /*
    |--------------------------------------------------------------------------
    | Expiration Time
    |--------------------------------------------------------------------------
    |
    | The number of seconds after which cached items should expire.
    | Setting this value to null will store cached items indefinitely
    | (until manually invalidated). If you want automatic cleanup or
    | to avoid stale data, set this to something like 604800 (7 days).
    |
    */
    'expiration_time' => env('LADA_CACHE_EXPIRATION', 0),

    /*
    |--------------------------------------------------------------------------
    | Cache Granularity
    |--------------------------------------------------------------------------
    |
    | Determines how precisely the cache is tagged. When enabled (true),
    | cache invalidation happens at the row level, using primary keys to
    | generate tags for each database query. This provides maximum accuracy
    | but may produce more Redis keys.
    |
    | If you experience issues or performance degradation, set this to false
    | to reduce granularity. This tells Lada Cache to ignore row-level keys
    | and cache results at a broader level.
    |
    */
    'consider_rows' => env('LADA_CACHE_CONSIDER_ROWS', true),

    /*
    |--------------------------------------------------------------------------
    | Include Tables
    |--------------------------------------------------------------------------
    |
    | Use this array if you want to cache *only specific tables*.
    | Once any query involves a table not listed here, that query
    | will not be cached.
    |
    | If "include_tables" is not empty, "exclude_tables" will be ignored.
    |
    | Tip: Instead of hardcoding table names, use model instances:
    |
    | 'include_tables' => [
    |     (new \App\Models\User())->getTable(),
    |     (new \App\Models\Post())->getTable(),
    | ],
    |
    */
    'include_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Exclude Tables
    |--------------------------------------------------------------------------
    |
    | Use this array if you want to cache all tables *except* specific ones.
    | If a query touches any table listed here, it will not be cached.
    | This is the inverse of "include_tables".
    |
    */
    'exclude_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Debugbar Collector
    |--------------------------------------------------------------------------
    |
    | When enabled, Lada Cache will register a collector for the Laravel
    | Debugbar package, allowing you to view cache activity and hit/miss
    | statistics directly in your browser during development.
    |
    | This is useful for debugging and optimizing query performance.
    |
    */
    'enable_debugbar' => env('LADA_CACHE_ENABLE_DEBUGBAR', true),

];
