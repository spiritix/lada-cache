# Lada Cache <img src="https://cdn4.iconfinder.com/data/icons/vaz2101/512/face_1-512.png" height="40">

A **Redis-based**, fully automated, and scalable query cache layer for Laravel.

![Tests](https://github.com/spiritix/lada-cache/actions/workflows/tests.yml/badge.svg)
[![Coverage](https://codecov.io/gh/spiritix/lada-cache/branch/main/graph/badge.svg)](https://codecov.io/gh/spiritix/lada-cache)
[![Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

> **Lada Cache 6.x** - Updated for Laravel 12 and PHP 8.3+ with connection decorators, improved tagging, and addressing some old issues and bugs.

---

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

---

## Features

- 🚀 **Fully automated query caching** - no code changes required after setup  
- 🧩 **Granular invalidation** - automatically invalidates only affected rows or tables  
- 🧠 **Transparent integration** with Eloquent and Query Builder  
- ⚡ **Redis-backed** - in-memory speed, cluster-ready, horizontally scalable  
- 🧰 **Laravel Debugbar integration** - visualize cache hits, misses, and invalidations  
- 🎛️ **Fine-grained control** - include or exclude tables from caching  
- 🧱 **Connection decorator architecture** - DB queries pass through Lada transparently  

---

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

---

## Architecture

Lada Cache decorates Laravel’s database connections and query builders to intercept and cache all SQL operations automatically.

**Query lifecycle:**
1. Intercept query → Reflector analyzes SQL, bindings, and affected tables.  
2. Compute cache key → Based on SQL + parameters + database.  
3. Lookup in Redis → If cached, return immediately.  
4. Execute + store → If not cached, execute query and store result.  
5. Auto-invalidate → On any insert, update, delete, or truncate.  

The result is **automatic, consistent caching** across all database operations.

---

## Performance

Typical performance improvement: **10–30%** for most applications.  
In read-heavy or complex-query systems, reductions of **70–90% in database load** are possible.  

The more redundant or repetitive your queries, the more Lada Cache accelerates performance.

---

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

---

## Configuration
After publishing the configuration file (see [Installation](#installation)), you will find a comprehensive config file at `config/lada-cache.php`.

This file allows you to fine-tune cache behavior, such as:
- Enabling or disabling Lada Cache globally  
- Choosing the cache driver (Redis by default)  
- Setting key prefixes and expiration times  
- Defining which tables to include or exclude from caching  
- Enabling Debugbar integration for cache inspection  

The default configuration is already optimized for most Laravel applications, so you typically won’t need to modify it unless you want more granular control.

---

## Usage

After installation, Lada Cache works **automatically**.  
No code changes or caching calls are required - all database queries are transparently cached and invalidated.

You can control global behavior via `.env`:

```env
LADA_CACHE_ACTIVE=true
LADA_CACHE_DEBUGBAR=true
```

---

## Console Commands

```bash
# Flush all cached entries
php artisan lada-cache:flush

# Temporarily disable cache
php artisan lada-cache:disable

# Re-enable cache
php artisan lada-cache:enable
```

---

## Known Issues and Limitations

- Multiple connections (`DB::connection('foo')`) are supported only when wrapped by Lada’s `ConnectionDecorator`. Models defining `$connection` work automatically.  
- Cache must be flushed manually after migrations.  
- Pessimistic locks (`lockForUpdate`, `sharedLock`) require raw SQL.  
- Third-party packages with custom query builders may bypass caching.

---

## Contributing

Pull requests and issue reports are welcome!

- Follow **PSR-12** coding style  
- Add tests for all new features  
- Submit via feature branches (no direct PRs from `master`)

---

## License

Lada Cache is open-source software licensed under the **MIT License**.