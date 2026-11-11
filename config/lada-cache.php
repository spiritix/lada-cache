<?php

use Spiritix\LadaCache\CacheScope;
use Spiritix\LadaCache\EntrySizeLimit;
use Spiritix\LadaCache\ExpirationPolicy;
use Spiritix\LadaCache\Metrics;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Support\ReplicaReads;

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
    'redis_connection' => env('LADA_CACHE_REDIS_CONNECTION', Redis::DEFAULT_CONNECTION),

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
    'prefix' => env('LADA_CACHE_PREFIX', Redis::DEFAULT_PREFIX),

    /*
    |--------------------------------------------------------------------------
    | Expiration Time
    |--------------------------------------------------------------------------
    |
    | The number of seconds after which cached entries and tag versions
    | expire. Invalidated entries are never served, but they only leave
    | Redis once they expire, so this bounds memory usage. Set to 0 to
    | keep entries until the cache generation is retired.
    |
    */
    'expiration_time' => env('LADA_CACHE_EXPIRATION', ExpirationPolicy::DEFAULT_SECONDS),

    /*
    |--------------------------------------------------------------------------
    | Expiration Jitter
    |--------------------------------------------------------------------------
    |
    | Spreads each expiration randomly by up to this percentage of the
    | expiration time in either direction, so entries cached together do
    | not all expire, and hit the database, at the same moment. Set to 0
    | to expire every entry after exactly the configured time.
    |
    */
    'expiration_jitter' => env('LADA_CACHE_EXPIRATION_JITTER', ExpirationPolicy::DEFAULT_JITTER_PERCENT),

    /*
    |--------------------------------------------------------------------------
    | Maximum Entry Size
    |--------------------------------------------------------------------------
    |
    | Results whose encoded size exceeds this many bytes are returned but not
    | cached, since moving them through Redis costs about as much as running
    | the query again and blocks Redis while it happens. Set to 0 for no limit.
    |
    */
    'max_entry_size' => env('LADA_CACHE_MAX_ENTRY_SIZE', EntrySizeLimit::DEFAULT_BYTES),

    /*
    |--------------------------------------------------------------------------
    | Replica Lag
    |--------------------------------------------------------------------------
    |
    | Only applies to connections with separate read replicas (a "read"
    | section in the connection config). For this many seconds after a
    | write, results read from a replica for the written tables are
    | returned but not cached, since the replica may not have the write
    | yet. Set it to the replication lag you want to tolerate.
    |
    */
    'replica_lag' => env('LADA_CACHE_REPLICA_LAG', ReplicaReads::DEFAULT_LAG_SECONDS),

    /*
    |--------------------------------------------------------------------------
    | Invalidate Entries of Earlier Versions
    |--------------------------------------------------------------------------
    |
    | While a deploy runs, hosts on 5.x and hosts on this version keep their
    | entries and tags under different keys, so neither sees the other's
    | writes. Enable this during such a rollout: every write also deletes the
    | 5.x entries of the written tags, at the cost of one more Redis script
    | per write, and every read also depends on the 5.x tag sets of its tags,
    | at the cost of two more commands per tag, so a write by a 5.x host
    | invalidates what this version cached. Both versions must use the same
    | Redis database. Turn it off again once every host runs this version.
    |
    */
    'legacy_invalidation' => env('LADA_CACHE_LEGACY_INVALIDATION', false),

    /*
    |--------------------------------------------------------------------------
    | Dependent Tables
    |--------------------------------------------------------------------------
    |
    | Writes to a table also invalidate what the database changes along
    | with it: views reading the table, and tables reached through foreign
    | keys with ON DELETE / ON UPDATE CASCADE, SET NULL or SET DEFAULT.
    | Both are read from the schema automatically, as are the tables trigger
    | bodies write. List changes the schema does not reveal here, such as
    | tables written by procedures a trigger calls: 'orders' => ['order_totals'].
    |
    */
    'dependent_tables' => [],

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
    | Cache Scope
    |--------------------------------------------------------------------------
    |
    | Determines which reads are cached.
    |
    | "all":    every query on a Lada connection is cached, including
    |           DB::table() queries and models without LadaCacheTrait.
    | "models": only queries issued by models using LadaCacheTrait are
    |           cached. Writes from any builder still invalidate, so cached
    |           models never go stale through DB::table() or other models.
    |
    */
    'scope' => env('LADA_CACHE_SCOPE', CacheScope::All->value),

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
    | Tables Laravel writes on nearly every request when sessions, the cache
    | or queues use the database (sessions, cache, jobs, ...) are added
    | automatically from their configuration.
    |
    */
    'exclude_tables' => [],

    /*
    |--------------------------------------------------------------------------
    | Metrics
    |--------------------------------------------------------------------------
    |
    | Counts hits, misses, stores and invalidations per table, retires,
    | bypasses and failures, inside the Redis calls Lada Cache already
    | makes. `php artisan lada-cache:stats` reports them. Counters are kept
    | per time bucket of `metrics_bucket_seconds` and expire after
    | `metrics_retention_seconds`.
    |
    */
    'metrics' => env('LADA_CACHE_METRICS', false),

    'metrics_bucket_seconds' => env('LADA_CACHE_METRICS_BUCKET', Metrics::DEFAULT_BUCKET_SECONDS),

    'metrics_retention_seconds' => env('LADA_CACHE_METRICS_RETENTION', Metrics::DEFAULT_RETENTION_SECONDS),

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
