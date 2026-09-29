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

Use `withoutCache()` to opt a single query out of caching. The query skips both read caching and write invalidation, then continues through Laravel as usual:

```php
// Eloquent
$user = User::where('id', $id)->withoutCache()->first();

// Query Builder
DB::table('users')->where('id', $id)->withoutCache()->update(['name' => 'New']);
```

This is useful for freshness-critical reads, diagnostics, or ad-hoc cache busting without touching the configuration.

## Console Commands

```bash
# Flush all cached entries
php artisan lada-cache:flush

# Temporarily disable cache
php artisan lada-cache:disable

# Re-enable cache
php artisan lada-cache:enable

# Auto-calibrate per-model TTLs from Redis OBJECT IDLETIME (see Auto-calibration below)
php artisan lada-cache:calibrate              # dry-run
php artisan lada-cache:calibrate --apply      # persist results
```

## Auto-calibration

Picking the right TTL per model is a guessing game without production data.
`lada-cache:calibrate` removes the guesswork: it samples Redis `OBJECT IDLETIME`
for cached keys belonging to each Lada-cached model, combines that with recent
cache activity, and derives a per-model TTL via:

```
calibrated_ttl = max(ceil(P95 * safety_factor), floor(previous_ttl / 2))
```

The floor term protects against survivor bias — `OBJECT IDLETIME` can only
sample keys that haven't yet been evicted, so successive runs would otherwise
shrink TTLs monotonically toward zero.

Results are stored in the `lada_cache_calibrations` table (shipped via package
migration) and consumed by `TtlResolver` between the `HasLadaTtl` interface
and the static `model_ttls` config map.

### Enable

```env
LADA_CACHE_CALIBRATION_ENABLED=true        # default: false
LADA_CACHE_CALIBRATION_SAFETY_FACTOR=2.0   # P95 multiplier
LADA_CACHE_CALIBRATION_MIN_SAMPLES=50      # skip models with fewer samples
LADA_CACHE_CALIBRATION_CACHE_TTL=300       # in-memory map cache, seconds
LADA_CACHE_CALIBRATION_SCHEDULE_INTERVAL=7 # run once every 7 days; 0 = no auto-schedule
```

Then publish & run the package migration:

```bash
php artisan vendor:publish --tag=migrations
php artisan migrate
```

### Auto-scheduled run

The package auto-registers the calibration cron via
`callAfterResolving(Schedule::class)` when calibration is enabled and
`schedule_interval` is greater than zero. The default is **once every 7 days**
at 03:00 — long enough for TTLs to converge on real access patterns without
bombing Redis with daily scans.

To customise, set `LADA_CACHE_CALIBRATION_SCHEDULE_INTERVAL` to the number of
days between runs, or set it to `0` and register the command yourself:

```php
// routes/console.php (Laravel 11+) or app/Console/Kernel.php
Schedule::command('lada-cache:calibrate --apply')->weekly();
```

The auto-registered job runs with `->onOneServer()`, `->withoutOverlapping()`,
and `->runInBackground()` — safe under multi-server Horizon deployments.

### Safety

- Refuses to run when Redis `maxmemory-policy` is `*-lfu` (IDLETIME is
  unsupported under LFU eviction — using it would calibrate every TTL
  toward zero).
- Dry-run by default; `--apply` is required to mutate `lada_cache_calibrations`.
- Skips models with fewer than `min_samples` data points (including 0).
- Pipelined `OBJECT IDLETIME` calls and cursor-driven `SSCAN`/`SCAN` keep
  the command non-blocking even against millions of cached keys. The tag
  keyspace is scanned once per run, not once per model.

### Activity-aware calibration

While calibration is enabled, Lada records lightweight per-table activity and
uses it on the next `lada-cache:calibrate` run. Each model is labeled with a
signal source:

| Signal | When | Effect |
|--------|------|--------|
| `idletime_only` | Activity read unavailable or Redis lookup failed | Original IDLETIME-only TTL |
| `no_activity` | reads + writes below `min_reads_for_signal` | Original IDLETIME-only TTL |
| `write_heavy` | invalidates / (hits+misses) ≥ `write_heavy_ratio` | Skip survivor-bias floor (writes were going to invalidate anyway) |
| `read_heavy` | otherwise | Hit-ratio proportional control toward `target_hit_ratio` |

Tuning knobs (all `LADA_CACHE_CALIBRATION_*` env vars):

```env
LADA_CACHE_CALIBRATION_ACTIVITY_LOOKBACK_HOURS=168      # 7 days
LADA_CACHE_CALIBRATION_MIN_READS_FOR_SIGNAL=10
LADA_CACHE_CALIBRATION_WRITE_HEAVY_RATIO=0.5
LADA_CACHE_CALIBRATION_TARGET_HIT_RATIO=0.80
LADA_CACHE_CALIBRATION_HIT_RATIO_DEADBAND=0.05
LADA_CACHE_CALIBRATION_HIT_RATIO_LEARNING_RATE=0.30
LADA_CACHE_CALIBRATION_HIT_RATIO_MAX_STEP=0.20
LADA_CACHE_CALIBRATION_ACTIVITY_BUCKET_TTL_SECONDS=604800
```

The calibration log line includes per-signal counters so you can graph the
distribution of read-heavy vs write-heavy models across runs.

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

## License

Lada Cache is open-source software licensed under the **MIT License**.
