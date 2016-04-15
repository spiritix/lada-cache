# Lada Cache

A Redis based, automated and scalable database caching layer for Laravel 5.1+

[![Build Status](https://travis-ci.org/spiritix/lada-cache.svg?branch=master)](https://travis-ci.org/spiritix/lada-cache)
[![Code Climate](https://codeclimate.com/github/spiritix/lada-cache/badges/gpa.svg)](https://codeclimate.com/github/spiritix/lada-cache)
[![Total Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Stable Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Unstable Version](https://poser.pugx.org/spiritix/lada-cache/v/unstable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

## Features

- Automatically caches all database queries
- Intelligent cache invalidation with high granularity
- Works with existing code, no changes required after setup
- Possibility to cache only specific models or exclude some models
- Makes use of [Laravel Redis](http://laravel.com/docs/5.2/redis) (supports [clustering](https://laravel.com/docs/5.2/redis#introduction))
- PHP7 and HHVM ready

## Why?

Most RDBMS provide internal caching systems (for example Mysql Query Cache). Unfortunately these caching systems have some very serious limitations:

- They do not cache queries over multiple tables (especially joins)
- The invalidation granularity is very low
- They are not distributed, if you have multiple database servers the cache will be created on all of them
- They are not scalable

This library offers a solution for all of these problems.

## Why only Redis?

As you may have discovered while looking at the source code, this library is built directly on top of [Laravel Redis](http://laravel.com/docs/5.2/redis) and not [Laravel Cache](http://laravel.com/docs/5.2/cache) which would make more sense from a general point of view.
However, there are several important reasons for this decision:

- Storage must be in-memory (wouldn't make much sense otherwise)
- Storage must be easily scalable (try to implement that with for example Memcached)
- Storage must support tags. Redis provides the set data type which allows a very easy and fast implementation. One may argue that Memcached also support tags, but that's a widespread misapprehension. It is possible to implement tags in Memcached using [this approach](http://dev.venntro.com/2010/08/memcached-invalidation-for-sets-of-keys/), but this results in 1+[quantity of tags] requests for every read operation which is not very efficient.

If you still want to use another storage system, please feel free to contribute.

## Performance

Due to the fact that Redis is faster than for example MySQL, a performance gain of 30-50% is possible even for very simple and fast queries (<0.001s). However the cache starts getting very efficient with more complex queries (> 0.01s, 90% performance gain, > 0.1s, 99% performance gain). Please note that these benchmarks have been done for queries that don't return much data. If your query is very simple but returns 1GB of data, the cache won't make it faster at all.

In a typical web application the time consumed for database interaction is usually only 5 - 20%, so expect a performance gain somewhere in this area. 

## Should I use it

#### No
- The percentage of time spent for database interaction in your overall page loading time is smaller than ~10%
- Your queries are typically of a low complexity
- Your queries are typically returning a big amount of data

#### Yes
- More than ~10% of page loading time spent in database
- Your queries are typically of a medium to high complexity
- Your queries are typically returning a low to medium amount of data
- You want to reduce the load on your database server(s)

## Requirements

- PHP 5.5+
- Redis 2+
- Laravel 5.1+
- [Predis](https://github.com/nrk/predis) 
- [Phpiredis](https://github.com/nrk/phpiredis) increases cache performance (optional)
- [Laravel Debugbar](https://github.com/barryvdh/laravel-debugbar) provides debug information (optional)

## Installation

Lada Cache can be installed via [Composer](http://getcomposer.org) by requiring the
`spiritix/lada-cache` package in your project's `composer.json`.

```json
{
    "require": {
        "spiritix/lada-cache": "@stable"
    }
}
```

Then run a composer update
```sh
php composer.phar update
```

Now you must register the service provider when bootstrapping your Laravel application.
Find the `providers` key in your `config/app.php` and register the Lada Cache Service Provider.

```php
    'providers' => array(
        // ...
        Spiritix\LadaCache\LadaCacheServiceProvider::class,
    )
```

Finally all your models must extend the `Spiritix\LadaCache\Database\Model` class.
It's a good practice to create a base model class which extends the Lada Cache model and then gets extended by all your models.

```php
class Post extends Spiritix\LadaCache\Database\Model {
    //
}
```

_Don't try to only have specific models extending the Lada Cache model, this will result in unexpected behavior.
In the configuration you will find the possibility to include or exclude specific models._

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

If you want to temporary disable the cache (for example before running migrations), use these commands:

```shell
php artisan lada-cache:disable
php artisan lada-cache:enable
````

## Known issues and limitations

- Does not work with [raw SQL queries](http://laravel.com/docs/5.2/database#running-queries). This would require an SQL parser to be implemented which is quite hard and very inefficient. As long as you are only using raw queries for reading data, it just won't get cached. Serious issues will only occur if you use raw queries for writing data (which you shouldn't be doing anyway).
- Invalidation on row level [does only work](https://github.com/spiritix/lada-cache/issues/16) if you use ``ìd`` as column name for your primary keys.
- Cache must be truncated manually after migrations are executed.

## Contributing

Contributions in any form are welcome.
Please consider the following guidelines before submitting pull requests:

- **Coding standard** - It's mostly [PSR-2](https://github.com/php-fig/fig-standards/blob/master/accepted/PSR-2-coding-style-guide.md) with some differences. 
- **Add tests!** - Your PR won't be accepted if it doesn't have tests.
- **Create feature branches** - I won't pull from your master branch.

## License

Lada Cache is free software distributed under the terms of the MIT license.