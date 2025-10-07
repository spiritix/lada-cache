# Lada Cache <img src="https://cdn4.iconfinder.com/data/icons/vaz2101/512/face_1-512.png" height="40">

A **Redis-based**, fully automated, and scalable query cache layer for Laravel.

![Tests](https://github.com/spiritix/lada-cache/actions/workflows/tests.yml/badge.svg)
[![Coverage](https://codecov.io/gh/spiritix/lada-cache/branch/main/graph/badge.svg)](https://codecov.io/gh/spiritix/lada-cache)
[![Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

> **Lada Cache 6.x** - Updated for Laravel 12 and PHP 8.3+ with connection decorators, improved tagging, and addressing a number of old issues and bugs.

## Table of Contents

- [Features](#features)
- [Version Compatibility](#version-compatibility)
- [Architecture](#architecture)
- [Performance](#performance)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Console Commands](#console-commands)
- [Known Limitations](#known-limitations)
- [Contributing](#contributing)
- [License](#license)

## Features

- 🚀 **Fully automated query caching** - no code changes required after setup  
- 🧩 **Granular invalidation** - automatically invalidates only affected rows or tables  
- 🧠 **Transparent integration** with Eloquent and Query Builder  
- ⚡ **Redis-backed** - in-memory speed, cluster-ready, horizontally scalable  
- 🧰 **Laravel Debugbar integration** - visualize cache hits, misses, and invalidations  
- 🎛️ **Fine-grained control** - include or exclude tables from caching  
- 🧱 **Connection decorator architecture** - DB queries pass through Lada transparently

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

## Architecture

Lada Cache decorates Laravel’s database connections and query builders to intercept and cache all SQL operations automatically.

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
- Choosing the cache driver (Redis by default)  
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

## License

Lada Cache is open-source software licensed under the **MIT License**.