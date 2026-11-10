# CHANGELOG

## [Unreleased]
### Upgrading
- Entries use a new key layout and invalidation scheme, so hosts still running an earlier version neither read them nor invalidate them. Set `LADA_CACHE_LEGACY_INVALIDATION=true` for the duration of a rolling deploy, with both versions on the same Redis database, so writes by either version invalidate the entries of the other, and turn it off afterwards.
- Migrations must run on a host with Lada Cache active against the shared Redis, since schema changes are detected from migration events only.

### Added
- `lada-cache.legacy_invalidation` (`LADA_CACHE_LEGACY_INVALIDATION`, off by default): during a rolling deploy, writes also delete the 5.x entries of the tags they invalidate, so pods still on 5.x never serve results this version invalidated, and reads depend on the 5.x tag sets of their tags, so pods on this version never serve results a 5.x pod invalidated; reads pinned to rows also depend on their table's unspecific tag, so rows created by 5.x pods reach cached lookups of them
- Added `php artisan lada-cache:invalidate <tables>` to invalidate specific tables after changes made outside Laravel, such as imports or manual fixes, without flushing the cache
- Added `withoutCache()` to read a query from the database without caching it (see https://github.com/spiritix/lada-cache/pull/151); writes invalidate regardless, since skipping their invalidation left other readers with stale rows
- Added `lada-cache.scope` (`LADA_CACHE_SCOPE`): `models` caches only reads from models using `LadaCacheTrait`, while writes from any builder still invalidate
- Added `lada-cache.expiration_jitter` (`LADA_CACHE_EXPIRATION_JITTER`, default 15): spreads each expiration by up to that percentage so entries cached together do not expire together (based on https://github.com/spiritix/lada-cache/pull/154)
- Writes invalidate the tables the database changes through foreign keys with cascading or nulling actions, read from the schema once per cache generation: deletes follow delete rules, updates follow update rules only when they assign a referenced column, a nulled child stops a delete from reaching the tables below it, writes affecting no rows, from the builder or raw `DB::update()`/`DB::delete()`, fire no foreign key, a delete pinned to primary key values invalidates only the cached child reads that may contain the rows its single-column CASCADE and SET NULL keys change, leaving reads pinned to other parents cached, and a truncate invalidates every referencing table, as PostgreSQL's `TRUNCATE ... CASCADE` empties them whatever their rules. Raw writes are classified by kind, so a raw `INSERT` no longer fires foreign keys and only `ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE` / `REPLACE` do; `lada-cache.dependent_tables` adds undeclared dependencies such as triggers
- Writes invalidate cached reads of views over the written tables, read from the schema like foreign keys
- SQL Lada reads itself (raw statements, raw expressions, view and trigger definitions) is parsed with a port of Apache DataFusion's sqlparser-rs tokenizer and its table and DML parsing for MySQL/MariaDB, PostgreSQL, SQLite and SQL Server, instead of regular expressions: quoting, comments, dollar-quoted and escaped strings and aliases follow each database's rules. Raw multi-table `UPDATE` and `DELETE` invalidate the tables they write, resolved through their aliases, instead of the whole cache; schema-qualified writes outside MySQL invalidate their table; `MERGE` follows insert, update and delete effects only; SQL Server batches that hide a second write without a semicolon change everything; raw updates and upserts are column-precise; reads whose FROM clause has a shape the parser does not know run uncached rather than risk an unknown table
- Writes invalidate tables their triggers write, read from the schema with each trigger's events (`INSERT`, `UPDATE [OF columns]`, `DELETE`, `TRUNCATE`) and the kind of write its body makes to each table, so an `AFTER INSERT` audit trigger is followed only by inserts; writes through updatable views invalidate their base tables
- Added `lada-cache.replica_lag` (`LADA_CACHE_REPLICA_LAG`, default 2): on connections with read replicas, replica reads of recently written tables are not cached
- Added `lada-cache.max_entry_size` (`LADA_CACHE_MAX_ENTRY_SIZE`, default 8 MB): larger results are returned but not cached
- Invalidations Redis rejects are kept, shared through APCu under PHP-FPM, and replayed before the cache is used again; reads bypass the cache until then. They are replayed again when the request, worker or `lada-cache:invalidate` ends, ignoring the failure cooldown, so a process that ends within 30 seconds of the rejection does not take them with it
- Tables Laravel writes on nearly every request when sessions, cache or queues use the database are excluded from caching automatically, saving a lookup and store per request for entries the next write discards
- Under Laravel Octane, including FrankenPHP worker mode, Lada's services are added to `octane.warm`, so the failure cooldown and invalidations awaiting replay live as long as the worker instead of one request
- A restarted Redis or a promoted replica, which may have lost the newest invalidations, retires the cache on the first store or invalidation that reaches it
- Invalidations merged over large transactions or backlogs collapse into table tags past 1000 row tags, so a bulk import commits with one small script instead of setting a version key per row
- A Rollout suite (`just rollout`) running hosts of 5.x and of this version side by side against one database and Redis
- Fuzz and multi-process test suites, and a test matrix over SQLite, MySQL and PostgreSQL with both Redis clients

### Changed
- Development runs on the Nix flake: PHP 8.4 with the extensions the suites need, Composer and just come from `nix develop`, and the databases from `just services`, which publishes them on ports of their own so a local MySQL, PostgreSQL or Redis does not clash. `make` targets became `just` recipes; `composer.lock` is committed and PHPStan and Pint run as real gates
- Cache entries are scoped by a random generation id stored in Redis. Each completed migration and every flush replace it atomically, so results read before a schema change are never served afterwards, even if the migration batch fails midway or Redis is emptied. Retired generations are pruned in bounded `SCAN` batches when a migration batch finishes instead of a blocking `KEYS` scan. Existing entries from earlier versions are ignored and removed on the next prune
- Replaced tag sets with per-tag version tokens checked atomically in Lua: a result read before a concurrent write is never served, a cache hit costs one Redis round trip instead of one plus one per tag, and an invalidation costs one round trip instead of five per tag
- Cache failures never affect database access: they are logged (errors for failed invalidations) and the query runs uncached; after a failure Redis is skipped for 30 seconds per process. Redis is no longer contacted when query builders are created
- `expiration_time` defaults to 7 days so invalidated entries leave Redis
- Removed `Invalidator` and the `lada.invalidator` binding; invalidation is part of `Cache`
- An invalid `lada-cache.scope`, `expiration_time` or `expiration_jitter` disables Lada Cache with a logged error instead of failing the application boot
- Subquery tables are tracked on the builder that owns them instead of on the shared query handler, which also lets `include_tables`/`exclude_tables` see them
- `schema:load`, `migrate:fresh`/`refresh` and raw DDL retire the generation; retiring after a schema change retries for about a second and ignores the failure cooldown, and a schema change inside a transaction retires again when it ends
- The failure cooldown is shared across PHP-FPM requests and processes through APCu when available; under FrankenPHP worker mode and Octane it lasts for the worker. Pending transaction invalidations are held per connection object, so purged connections in long-lived workers leave nothing behind
- Lua scripts run by SHA (`EVALSHA`, falling back to `EVAL` after `SCRIPT FLUSH`), so their source is no longer sent with every query
- Cached results only decode to arrays of `stdClass` rows; a tampered entry can no longer instantiate arbitrary classes
- Queries calling time-, randomness- or session-dependent SQL functions such as `NOW()` or `RAND()` are no longer cached
- Raw statements are classified once where every statement passes through the connection, so driver-specific overrides are covered
- With `consider_rows` disabled, tags separate database and table names, so database `a` with table `bc` and database `ab` with table `c` no longer share tags
- Row tags treat primary keys the way databases compare them: "05", 5 and 5.0 share a tag, string keys of models ignore ASCII case, and keys a database may cast or collate differently, or more than 100 per table, fall back to table tags so a query never reads or writes an unbounded number of tags
- `exists()` caches `select 1` instead of whole rows
- Lada connections no longer open the primary and read PDO connections when resolved, only when first used
- The debug collector lookup no longer throws and catches an exception for every query when Debugbar is not installed
- `include_tables` and `exclude_tables` must be lists of table names and `prefix` and `redis_connection` strings; any other value disables Lada Cache with a logged error at boot instead of being cast, or failing on the first query. Integer settings accept integers and integer strings only, no longer floats or booleans
- `QueryBuilder`'s constructor is `final` and takes an `Illuminate\Database\Connection` instead of any `ConnectionInterface`
- A write on a connection without a name logs a failed invalidation and invalidates everything, instead of invalidating under an empty connection name

### Fixed
- A failing SQL query was executed twice, and a successful query ran again when storing its result failed
- Tables read by subqueries Laravel compiles to raw SQL were not tagged, so writes to them never invalidated the cached result: `whereIn()`/`whereNotIn()` subqueries (https://github.com/spiritix/lada-cache/pull/157), `fromSub()` (https://github.com/spiritix/lada-cache/pull/160), subquery columns in `where()`/`whereBetween()`, `orderBy()` subqueries, `crossJoinSub()`, `joinLateral()`, subqueries inside join closures, `selectSub()`/`joinSub()` with a closure or Eloquent builder, and unions with an Eloquent builder
- `selectSub()`/`joinSub()` subquery tags were handed to whichever query ran next instead of the query they belonged to
- Top-level `OR` and `whereNot` queries were scoped to the primary-key rows of one branch, leaving them stale after writes to other rows (https://github.com/spiritix/lada-cache/pull/156)
- `findMany()`, `whereKey([...])` and BelongsTo eager loads were tagged table-wide and evicted by every write to the table (https://github.com/spiritix/lada-cache/pull/158)
- Cached lookups of a missing row survived writes that created that row: `insertGetId()` and inserts with an explicit key (https://github.com/spiritix/lada-cache/pull/155), inserts without a key, `insertOrIgnore()`, `insertUsing()`, `insertOrIgnoreUsing()`, and updates that change the primary key
- Raw statements (`DB::insert/update/delete/statement/affectingStatement/unprepared`, and writes through `DB::select()`) never invalidated anything
- Raw `DB::insert()` on MySQL bypassed raw statement invalidation, because MySQL's connection overrides `insert()`
- `lada-cache:enable` left results cached before Lada was disabled in place, although writes made while it was disabled never invalidated them; enabling now retires them
- Writes cascading through foreign keys left cached reads of the cascaded tables stale
- A read keyed `where id = '05'` stayed stale after a write keyed `where id = 5`, and string keys differing only in case missed each other on case-insensitive collations
- Updates that set the primary key to an expression did not invalidate cached reads of the new key
- `EXPLAIN ANALYZE` of a write, which PostgreSQL executes, did not invalidate
- `fetchUsing()` fetch modes were dropped on Lada connections, and their results would have been cached under the default mode's key
- Reads inside `DB::pretend()` cached the empty result pretending returns, so later real reads returned nothing
- Reads naming tables only inside raw SQL (`whereRaw`, `selectRaw`, raw joins, `fromRaw`) were never invalidated by writes to those tables
- Writes to tables in `exclude_tables` or missing from `include_tables` did not invalidate, so foreign key actions carrying them into cached tables left those stale
- Equally named databases on different servers shared cache entries, so one could serve the other's rows; entries are now keyed by driver, host, port and socket as well
- On MySQL, reads of `otherdb.table` and writes to `table` on a connection to `otherdb` (or the reverse) missed each other; database-qualified tables are now tagged under their database
- `insertOrIgnoreReturning()` never invalidated anything
- `updateOrInsert()` with callback values failed with a `TypeError`
- A nested transaction commit flushed invalidations before the outer transaction committed, letting other hosts cache uncommitted-era results, and a savepoint rollback discarded the invalidations of the enclosing transaction
- A Redis connection failure under phpredis bypassed the failure cooldown, costing a connection attempt per query

## [6.1.1] - 2026-05-22
### Fixed
- Fixed cache key collision for deferred query mutations (see https://github.com/spiritix/lada-cache/pull/150)
- Fixed query handler performance issue (see https://github.com/spiritix/lada-cache/issues/149)

## [6.1] - 2026-03-21
### Added
- Added support for Laravel 13
- Increased test coverage for DB integration layer

### Changed
- Switched Laravel Debugbar integration to fruitcake/laravel-debugbar

## [6.0.3] - 2025-11-11
### Fixed
- Fixed nested join clause bug (see https://github.com/spiritix/lada-cache/issues/142)
- Fixed bug in invalidation and row-level tagging (see https://github.com/spiritix/lada-cache/issues/141)

## [6.0.2] - 2025-11-04
### Fixed
- Fixed row-level invalidation handling (see https://github.com/spiritix/lada-cache/issues/140)
- Fixed returning created IDs (see https://github.com/spiritix/lada-cache/issues/139)

## [6.0.1] - 2025-10-16
### Fixed
- Fixed connection driver delegation (see https://github.com/spiritix/lada-cache/issues/138)
- Fixed service provider when disabled (see https://github.com/spiritix/lada-cache/issues/137)

## [6.0] - 2025-10-07
### Added
- Laravel 12 and PHP 8.3 support
- Connection integration via DB::extend() for core drivers 
- Auto-flush after migrations (MigrationsEnded event)
- Selectable Redis connection (lada-cache.redis_connection, default "cache")

### Changed
- Pessimistic locks (lockForUpdate/sharedLock) bypass cache
- exists() uses cached select path when not locked
- Added invalidation for insertUsing/insertOrIgnoreUsing/updateFrom
- Tag sets self-heal on cache hits (idempotent SADD)
- Cache::flush() prefers non-blocking UNLINK (falls back to DEL)

### Fixed
- No Redis initialization when disabled; flush command is a no-op when inactive
- Reflector detects joinSub tables and strips aliases across from/join/where
- Reflector handles Expression columns and improves UNION/EXISTS traversal
- exclude_tables respected by Manager

## [5.4] - 2024-03-17
### Added
- Added support for Laravel 11

## [5.3.1] - 2024-02-01
### Fixed
- Fixed stale state bug (see https://github.com/spiritix/lada-cache/pull/124)

## [5.3] - 2023-02-20
### Added
- Added support for Laravel 10

## [5.2] - 2022-02-14
### Added
- Added support for Laravel 9

## [5.1.2] - 2021-05-20
### Fixed
- Fixed bug in query builder related to primary key names (#104, #103, #91)

## [5.1.1] - 2021-03-19
### Fixed
- Fixed bugs related to PHP 8.0 (#102)

## [5.1] - 2020-01-10
### Added
- Added support for Laravel 8

## [5.0.2] - 2020-06-02
### Fixed
- Hotfix for previous release

## [5.0.1] - 2020-05-25
### Fixed
- Fixed bug with Laravel Telescope (#91)

## [5.0] - 2020-03-18
### Added
- Added support for Laravel 7

## [4.0.2] - 2020-01-23
### Fixed
- Fixed bug with ignoring disabled cache in invalidation (#86)

## [4.0.1] - 2019-12-18
### Fixed
- Hotfix for merge issue

## [4.0] - 2019-12-18
### Added
- Added support for Laravel 6

## [3.0.4] - 2019-11-22
### Fixed
- Fixed missing table tag for WhereHas/WhereDoesntHave functions

## [3.0.3] - 2019-05-24
### Fixed
- Fixed exception caused by raw queries (#66)

## [3.0.2] - 2019-05-08
### Fixed
- Fixed limitation with hardcoded primary key names (#16)

## [3.0.1] - 2019-05-08
### Fixed
- Fixed bug with updating pivot tables (#60)

## [3.0] - 2018-11-15
### Fixed
- Fixed bug caused by 'leftJoinSub' in Laravel 5.7

### Changed
- Updated minimum requirements to PHP 7.1+
- Updated minimum requirements to Laravel 5.7+

## [2.1.2] - 2018-05-14
### Fixed
- Fixed race condition which possibly led to outdated query results being returned after keys expire

## [2.1.1] - 2018-01-29
### Fixed
- Fixed bug in publishing config

### Added
- Added a basic Wiki

## [2.1] - 2017-12-05
### Fixed
- Fixed bug with subqueries (#46)

### Changed
- Updated README

### Added
- Added support for Laravel 5.5

## [2.0] - 2017-07-20
### Changed
- Now using a trait instead of a base model class

### Added
- Added support for Laravel 5.4

## [1.4.2] - 2016-12-09
### Fixed
- Fixed bug in debug bar collector

### Changed
- Optimized reflector for some edge cases

## [1.4.1] - 2016-12-07
### Fixed
- Fixed bug regarding union select queries

## [1.4] - 2016-12-07
### Fixed
- Fixed a bug in relation to the Debug Bar, write queries were not displayed properly
- Fixed bug in invalidator (#30)

### Changed
- Improved caching strategy
- Refactored parts of the library

### Added
- Added a lot of new tests

## [1.3.1] - 2016-09-20
### Added
- Added support for PhpRedis

### Changed
- Refactored and improved service provider

### Fixed
- Fixed bug with tag invalidation

## [1.3] - 2016-09-19
### Changed
- Changed the encoder to make use of serialization instead of JSON since there were issues with associative arrays

### Fixed
- Fixed bug with installed but not enabled debug bar (#26)
- Fixed bug with Redis 'exists' return value

## [1.2.2] - 2016-05-18
### Fixed
- Fixed bug with Travis CI (#19)
- Fixed bug in query builder (#21)

### Changed
- Improved unit tests

## [1.2.1] - 2016-05-15
### Fixed
- Fixed expiration time bug

## [1.2] - 2016-05-15
### Added
- The changelog
- A collector for Laravel Debugbar 
- It's now possible to set the expiration time in the configuration
- Integration tests

### Changed
- PHP version dependency from 5.6 to 5.5
- Refactored parts of the library, reflectors concept was revised, performance optimized

### Fixed
- Fixed bug in reflector (#11)

## [1.1.1] - 2016-03-20
### Fixed
- Fixed critical bug, invalidating multiple tags was not working

## [1.1] - 2016-02-11
### Added
- It's now possible to cache only specific models or exclude some of them

### Changed
- Refactored most of the classes and the structure of the library

### Fixed
- Fixed various major and minor bugs

## [1.0] - 2015-12-02
### Added
- Initial stable release
