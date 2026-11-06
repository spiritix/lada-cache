# Lada Cache <img src="https://cdn4.iconfinder.com/data/icons/vaz2101/512/face_1-512.png" height="40">

A **Redis-based**, fully automated, and scalable query cache layer for Laravel.

[![Tests](https://github.com/spiritix/lada-cache/actions/workflows/tests.yml/badge.svg)](https://github.com/spiritix/lada-cache/actions/workflows/tests.yml)
[![Coverage](https://codecov.io/gh/spiritix/lada-cache/branch/master/graph/badge.svg)](https://codecov.io/gh/spiritix/lada-cache)
[![Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

> **Lada Cache 6.x - a focused rewrite for Laravel 12 / 13 and PHP 8.3, addressing many long-standing issues and adding new features.**

## Table of Contents

- [Features](#features)
- [Version Compatibility](#version-compatibility)
- [Architecture](#architecture)
- [Performance](#performance)
- [Why?](#why)
- [Why only Redis?](#why-only-redis)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Console Commands](#console-commands)
- [Known Issues and Limitations](#known-issues-and-limitations)
- [Contributing](#contributing)
- [License](#license)

## Features

- 🚀 **Fully automated query caching** - no code changes required after setup  
- 🧩 **Granular invalidation** - automatically invalidates only affected rows or tables  
- 🧠 **Transparent integration** with Eloquent and Query Builder  
- ⚡ **Redis-backed** - in-memory speed, cluster-ready, horizontally scalable  
- 🧰 **Laravel Debugbar integration** - visualize cache hits, misses, and invalidations  
- 🎛️ **Fine-grained control** - include or exclude tables from caching  
- 🧱 **Connection integration** - DB queries pass through a Lada-aware connection subclass transparently

## Version Compatibility

| Laravel  |  PHP   | Lada Cache |
|:--------:|:------:|:----------:|
| 5.1-5.6  | 5.6.4+ |    2.x     |
| 5.7-5.8  |  7.1+  |    3.x     |
|   6.x    |  7.2+  |    4.x     |
|   7.x    |  7.2+  |    5.x     |
|   8.x    |  7.3+  |    5.x     |
| 9.x-11.x |  8.0+  |    5.x     |
|  12.x    |  8.3+  |    6.x     |
|  13.x    |  8.3+  |    6.x     |

## Architecture

Lada Cache integrates with Laravel’s database layer by registering a Lada-aware connection, which returns a custom query builder to intercept and cache SQL operations automatically.

**Query lifecycle:**
1. Intercept query → Reflector analyzes SQL, bindings, and affected tables.  
2. Compute cache key → Based on SQL + parameters + database.  
3. Lookup in Redis → If cached, return immediately.  
4. Execute + store → If not cached, execute query and store result.  
5. Auto-invalidate → On any insert, update, delete, or truncate.  

The result is **automatic, consistent caching** across all database operations.

## Performance

Real-world gains range from **5% to 95%**, depending on how many and how complex your queries are. Typical Laravel apps see **~10–30%** faster responses and a **significant drop in DB load**.

- Large payloads still cost to move/encode; e.g. a query returning ~500MB won’t get faster from caching alone.
- The more redundant and complex the queries per request, the bigger the benefit.
- Reduced database traffic can translate to lower infra cost and easier horizontal scaling.

## Why?

- **Database-heavy apps** (especially with Eloquent) often repeat the same queries and not all are efficient.
- **RDBMS internal caches** (e.g., MySQL Query Cache) have hard limits:
  - Do not cache multi-table queries (joins)
  - **Coarse invalidation** (row change can evict a whole table)
  - **Not distributed** across DB servers
  - **Poor scalability** under load
- **Laravel manual caching** requires manual invalidation or time-based expiry.

Lada Cache provides automated, granular, distributed caching with transparent invalidation and scale-out via Redis.

## Why only Redis?

- Requires **in-memory** storage for latency and throughput.
- Must be **easily scalable** and **distributed**.
- Needs **tagging** support for granular invalidation (Laravel Cache tags exist but are slow for this use case).

Therefore, Lada Cache builds directly on top of Laravel Redis. If you need another backend, contributions are welcome.

## Installation

Install via Composer:

```bash
composer require spiritix/lada-cache
```

Lada Cache registers itself automatically via **Laravel Package Discovery**.

Then, publish the config file:

```bash
php artisan vendor:publish --provider="Spiritix\LadaCache\LadaCacheServiceProvider"
```

Finally, ensure all your Eloquent models include the trait:

```php
use Spiritix\LadaCache\Database\LadaCacheTrait;

class Car extends Model
{
    use LadaCacheTrait;
}
```

> 💡 It’s best to add the trait in a shared `BaseModel` class that all models extend.

## Configuration
After publishing the configuration file (see [Installation](#installation)), you will find a comprehensive config file at `config/lada-cache.php`.

This file allows you to fine-tune cache behavior, such as:
- Enabling or disabling Lada Cache globally  
- Setting key prefixes and expiration times  
- Defining which tables to include or exclude from caching  
- Enabling Debugbar integration for cache inspection  

The default configuration is already optimized for most Laravel applications, so you typically won’t need to modify it unless you want more granular control.

## Usage

After installation, Lada Cache works **automatically**.  
No code changes or caching calls are required - all database queries are transparently cached and invalidated.

You can control global behavior via `.env`:

```env
LADA_CACHE_ACTIVE=true
LADA_CACHE_DEBUGBAR=true
```

### Bypassing the cache per query

Use `withoutCache()` to read a single query straight from the database without caching its result:

```php
// Eloquent
$user = User::where('id', $id)->withoutCache()->first();

// Query Builder
$rows = DB::table('users')->where('active', true)->withoutCache()->get();
```

This is useful for freshness-critical reads and diagnostics. Writes always invalidate, with or without `withoutCache()`, so other readers never see rows a write replaced.

### Caching only selected models

By default every query on a Lada connection is cached. Set `LADA_CACHE_SCOPE=models` to cache only reads issued by models that use `LadaCacheTrait`. `DB::table()` reads and models without the trait then always hit the database, while writes from any builder still invalidate, so cached models never go stale through code paths that are not cached themselves.

```env
LADA_CACHE_SCOPE=models
```

### Rolling deployments

Cache entries are stored under a random generation id kept in Redis. Every schema change replaces that id, which retires every entry for all hosts sharing the Redis instance in one atomic step: each completed migration, `schema:load`, `migrate:fresh`/`refresh`, raw DDL run through Laravel (`DB::statement('ALTER TABLE ...')`), and `lada-cache:flush`. Inside a transaction the retire happens again when the transaction ends, because a transactional schema change only becomes visible at commit. Retiring after a schema change keeps trying Redis for about a second, even while Redis is skipped after a failure. Retired entries are pruned in bounded `SCAN` batches when a migration batch finishes. A query that started before the switch can never store a result that is served afterwards, and because ids are random, an emptied or restarted Redis simply starts a new generation and never revives entries written under an earlier one.

Retiring is event driven only; Lada Cache never polls the database or Redis to detect schema changes. The host that runs migrations must therefore have Lada Cache active and use the same Redis connection as the hosts serving traffic; a failed retire there is logged as an error.

### Consistency and failure handling

Every tag carries a random version token. A read captures the tokens of its tags before querying the database and stores its result under their fingerprint; a write replaces the tokens of the tags it touches. A result read before a concurrent write therefore becomes unreachable instead of stale, and an evicted token only causes a miss. Each cache read, store and invalidation is a single atomic Lua script run by its SHA, so a cache hit costs one small Redis round trip.

Reads inside a transaction always go to the database, and invalidations of writes inside a transaction run when the outermost transaction commits; a rollback discards them, a savepoint rollback keeps those of the enclosing transaction.

Raw statements run through Laravel invalidate too. Every statement passing through the connection, whichever method issued it (`DB::insert()`, `DB::update()`, `DB::delete()`, `DB::statement()`, `DB::affectingStatement()`, `DB::unprepared()`, `DB::select()` with `INSERT ... RETURNING`, and driver-specific overrides such as MySQL's `insert()`), invalidates every entry of the single table it writes. Statements that cannot be attributed to one table, such as DDL, multi-table updates, stored procedure calls, writable CTEs or several statements at once, retire the whole cache. Reads, session statements (`SET`, `PRAGMA`, savepoints, locks) and temporary tables invalidate nothing. Writes that bypass Laravel's database connection, such as other applications or database consoles, are not seen.

Writes also invalidate the tables the database changes along with them: foreign keys with `ON DELETE` / `ON UPDATE` `CASCADE`, `SET NULL` or `SET DEFAULT`, followed transitively. The foreign keys are read from the schema with a single query the first time a write needs them in each cache generation, so a migration that changes them is picked up automatically. Changes the schema does not declare, such as tables written by triggers, are listed in `lada-cache.dependent_tables`.

Queries whose result depends on when or by whom they run are never cached: SQL calling `NOW()`, `CURRENT_TIMESTAMP`, `CURRENT_DATE`, `RAND()`, `UUID()`, `date('now')` and similar functions. Pass such values as bindings (for example `now()` from PHP) to keep the query cacheable. Reads are assumed to have no side effects: a query calling your own database functions that write or depend on state outside its tables must use `withoutCache()`.

On connections with read replicas (a `read` section in the connection config), results read from a replica are not cached for `lada-cache.replica_lag` seconds (2 by default) after a write to one of their tables, because the replica may not have the write yet. Reads on the write connection, sticky reads after a write, and reads with `useWritePdo()` are cached as usual.

If an invalidation cannot reach Redis, it is kept and replayed before the cache is read or written again; until the replay succeeds, reads bypass the cache. Under PHP-FPM the pending invalidations are shared across requests and processes through APCu when available. Very large backlogs retire the whole cache instead.

The cache key and the cached rows do not depend on the connection's session settings. If parts of your application change session settings that alter results, such as the MySQL or PostgreSQL session time zone for `TIMESTAMP` columns, use a separate connection or `withoutCache()` for those queries. Applications that share one Redis instance must each use their own `lada-cache.prefix`.

Lada Cache never breaks database access. If Redis is unreachable or a cache operation fails, the failure is logged and the query runs against the database as if Lada Cache were not installed. After a failure, Redis is skipped for 30 seconds, so an outage costs at most one connection attempt per worker in that window: under FrankenPHP worker mode or Octane the skip lives as long as the worker, and under PHP-FPM it is shared across requests and processes through APCu when the APCu extension is enabled (otherwise every request tries once). Set short `timeout` and `read_timeout` values on the Redis connection Lada Cache uses to bound that attempt. Failed invalidations are logged as errors because other hosts may serve the previous results until they expire (`LADA_CACHE_EXPIRATION`, 7 days by default).

Each expiration is spread randomly by up to `LADA_CACHE_EXPIRATION_JITTER` percent (15 by default) in either direction, so entries cached together, for example right after a deploy, do not all expire and hit the database at the same moment. Set it to 0 for exact expiration times.

## Console Commands

```bash
# Flush all cached entries
php artisan lada-cache:flush

# Temporarily disable cache
php artisan lada-cache:disable

# Re-enable cache
php artisan lada-cache:enable
```

## Known Issues and Limitations

- Multiple connections (`DB::connection('foo')`) are only supported when using Lada’s connection integration. Models defining `$connection` work automatically.  
- Third-party packages with custom query builders may bypass caching.
- Complex SQL constructs such as `UNION`/`INTERSECT`/advanced expressions may not be fully reflected for row-level tagging; invalidation falls back to table-level tags.
- Raw SQL executed directly via the connection (e.g., `DB::select()`, `DB::statement()`) is not cached by design.
- Row-level tagging relies on standard single-column primary keys. Composite or unconventional primary keys fall back to table-level invalidation.

## Contributing

Pull requests and issue reports are welcome!

- Follow **PSR-12** coding style  
- Add tests for all new features  
- Submit via feature branches (no direct PRs from `master`)

### Running the tests

The suites run in Docker against SQLite, MySQL 8.4 and PostgreSQL 17, with both Redis clients:

```bash
make test                 # SQLite with phpredis
make test-mysql           # MySQL
make test-pgsql           # PostgreSQL
make test-matrix          # every database with phpredis and Predis
make fuzz LADA_FUZZ_ITERATIONS=5000
make concurrency          # several processes against MySQL and PostgreSQL
```

- **Unit** and **Integration** cover behaviour case by case.
- **Fuzz** runs a seeded random workload of reads, writes, raw statements, transactions, foreign key cascades, cache flushes, an emptied Redis and evicted keys, and compares every cached read with the same query run without the cache. A failure prints the seed and the operations that led to it; rerun with `LADA_FUZZ_SEED=<seed>`.
- **Concurrency** forks worker processes, like pods sharing one database and Redis, runs the same workload in all of them at once, and checks that every cached read matches the database once they finish.

## License

Lada Cache is open-source software licensed under the **MIT License**.
