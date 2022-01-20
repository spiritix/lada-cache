# Lada Cache <img src="https://cdn4.iconfinder.com/data/icons/vaz2101/512/face_1-512.png" height="40">

A Redis based, fully automated and scalable database cache layer for Laravel

[![Build Status](https://travis-ci.org/spiritix/lada-cache.svg?branch=master)](https://travis-ci.org/spiritix/lada-cache)
[![Code Climate](https://codeclimate.com/github/spiritix/lada-cache/badges/gpa.svg)](https://codeclimate.com/github/spiritix/lada-cache)
[![Total Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Stable Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Unstable Version](https://poser.pugx.org/spiritix/lada-cache/v/unstable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

_Contributors wanted!
Have a look at the [open issues](https://github.com/spiritix/lada-cache/issues) and send me an email if you are interested in a quick introduction via Hangouts._

## Table of Contents

- [Features](#features)
- [Version Compatibility](#version-compatibility)
- [Performance](#performance)
- [Why?](#why)
- [Why only Redis?](#why-only-redis)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Console commands](#console-commands)
- [Known issues and limitations](#known-issues-and-limitations)
- [Contributing](#contributing)
- [License](#license)

For further information on how this library works and how to debug it please have a look at the [Wiki](https://github.com/spiritix/lada-cache/wiki).

## Features

- Automatically caches all database queries
- Intelligent cache invalidation with high granularity
- Works with existing code, no changes required after setup
- Possibility to cache only specific models or exclude some models
- Makes use of [Laravel Redis](https://laravel.com/docs/7.x/redis) (supports [clustering](https://laravel.com/docs/7.x/redis#configuration))

## Version Compatibility

 Laravel  | PHP       | Lada Cache
:---------|:----------|:----------
 5.1-5.6  | 5.6.4+    | 2.x
 5.7-5.8  | 7.1+      | 3.x
 6.x      | 7.2+      | 4.x
 7.x      | 7.2+      | 5.x
 8.x      | 7.3+      | 5.x

## Performance

The performance gain achieved by using Lada Cache varies between 5% and 95%. It heavily depends on the quantity and complexity of your queries. The more (redundant) queries per request your application fires and the more complex they are, the bigger the performance gain will be. Another important factor to consider is the amount of data returned by your queries, if a query returns 500MB of data, Lada Cache won't make it faster at all. Based on experience, the performance gain in a typical Laravel web application is around 10-30%.

Other than the performance gain, an essential reason to use Lada Cache is the reduced the load on the database servers. Depending on your infrastructure, this may result in reasonable lower cost and introduce new possibilities to scale up your application.

## Why?

A lot of web applications make heavy use of the database. Especially using an ORM like Eloquent, queries repeat often and are not always very efficient. One of the most common solutions for this problem is caching the database queries.

Most RDBMS provide internal cache layers (for example [Mysql Query Cache](https://dev.mysql.com/doc/refman/5.7/en/query-cache.html)).  Unfortunately, these caching systems have some very serious limitations:

- They do not cache queries over multiple tables (e.g. if the queries are using joins)
- The invalidation granularity is very low (if a single row changes, the entire table gets removed from the cache)
- They are not distributed, if you have multiple database servers the cache will be created on all of them
- They are not scalable

Laravel, on the other hand, provides the possibility to cache particular queries manually. The problem is that it doesn't invalidate the cached queries automatically, you'll need to let them expire after a certain time or invalidate them manually on all places where the affected data might be changed.

This library provides a solution for all of the mentioned problems. 
Install, scale up and lean back.

## Why only Redis?

As you may have discovered while looking at the source code, this library is built directly on top of [Laravel Redis](https://laravel.com/docs/7.x/redis) instead of [Laravel Cache](https://laravel.com/docs/7.x/cache), which would make more sense from a general point of view.
However, there are several important reasons behind this decision:

- Storage must be in-memory (wouldn't make much sense otherwise)
- Storage must be easily scalable 
- Storage must support tags (Laravel Cache does support tags, but the implementation is very bad and slow)

If you still want to use another storage backend, please feel free to contribute.

## Requirements

- PHP 7.3+
- Redis 2+
- Laravel 8.0+ (for older versions see [Version Compatibility](#version-compatibility))
- [PhpRedis](https://github.com/phpredis/phpredis) increases cache performance (optional but recommended)
- [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) provides debug information (optional)

## Installation

Lada Cache can be installed via [Composer](http://getcomposer.org) by requiring the `spiritix/lada-cache` package in your project's `composer.json`.
Or simply run this command:

```sh
composer require spiritix/lada-cache
```

The Lada Cache service provider will automatically be installed using [Package Discovery](https://laravel.com/docs/7.x/packages#package-discovery).

Finally, all your models must include the `Spiritix\LadaCache\Database\LadaCacheTrait` trait.
It's a good practice to create a base model class which includes the trait and then gets extended by all your models.

```php
class Car extends \Illuminate\Database\Eloquent\Model {

    use \Spiritix\LadaCache\Database\LadaCacheTrait;
    
    // ...
}
```

_Don't try to only have specific models including the Lada Cache trait, it will result in unexpected behavior.
In the configuration, you will find the possibility to include or exclude specific models._

## Configuration

Use the following command to publish the ``lada-cache.php``config file to your configuration folder:

```shell
php artisan vendor:publish 
```

## Console commands

You may truncate the cache by running the following command:

```shell
php artisan lada-cache:flush
```

If you want to temporarily disable the cache (for example before running migrations), use these commands:

```shell
php artisan lada-cache:disable
php artisan lada-cache:enable
````

## Known issues and limitations

- Doesn't work with [raw SQL queries](https://laravel.com/docs/7.x/database#running-queries). This would require an SQL parser to be implemented which is quite hard and very inefficient. As long as you are only using raw queries for reading data, it just won't get cached. Serious issues will only occur if you use raw queries for writing data (which you shouldn't be doing anyway).
- Doesn't work with [multiple connections](https://laravel.com/docs/7.x/database#using-multiple-database-connections) if done like ``DB::connection('foo')``. Instead, specify the ``protected $connection = 'foo';`` property in the relevant models.
- The cache must be truncated manually after migrations are executed.
- Pessimistic locking (sharedLock, lockForUpdate) requires usage of [raw SQL queries](https://github.com/spiritix/lada-cache/issues/49).
- Some third-party packages are not working well together with Lada Cache. A workaround for most of the issues can be found [here](https://github.com/spiritix/lada-cache/issues/99#issuecomment-1017250267).

## Contributing

Contributions in any form are welcome.
Please consider the following guidelines before submitting pull requests:

- **Coding standard** - It's [PSR-2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md)
- **Add tests!** - Your PR won't be accepted if it doesn't have tests.
- **Create feature branches** - I won't pull from your master branch.

## License

Lada Cache is free software distributed under the terms of the MIT license.
