# Lada Cache

A Redis based, automated and scalable database caching layer for Laravel 5+

[![Build Status](https://travis-ci.org/spiritix/lada-cache.svg?branch=master)](https://travis-ci.org/spiritix/lada-cache)
[![Code Climate](https://codeclimate.com/github/spiritix/lada-cache/badges/gpa.svg)](https://codeclimate.com/github/spiritix/lada-cache)
[![Test Coverage](https://codeclimate.com/github/spiritix/lada-cache/badges/coverage.svg)](https://codeclimate.com/github/spiritix/lada-cache)
[![Dependency Status](https://www.versioneye.com/user/projects/561bcc3ea193340f2800149b/badge.svg?style=flat)](https://www.versioneye.com/user/projects/561bcc3ea193340f2800149b)
[![Total Downloads](https://poser.pugx.org/spiritix/lada-cache/d/total.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Stable Version](https://poser.pugx.org/spiritix/lada-cache/v/stable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![Latest Unstable Version](https://poser.pugx.org/spiritix/lada-cache/v/unstable.svg)](https://packagist.org/packages/spiritix/lada-cache)
[![License](https://poser.pugx.org/spiritix/lada-cache/license.svg)](https://packagist.org/packages/spiritix/lada-cache)

## Features

Lada Cache will automatically cache all queries in a Redis database.
The library makes use of Laravel Redis which is based on predis/predis.

*Work in progress, use at your own risk!*

## Requirements

- PHP 5.6+
- Laravel 5+
- [Phpiredis](https://github.com/nrk/phpiredis) is optional but will increase performance

## Installation

Lada Cache can be installed via [Composer](http://getcomposer.org) by requiring the
`spiritix/lada-cache` package in your project's `composer.json`.

```json
{
    "require": {
        "spiritix/lada-cache": "dev-master"
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

```php
class Post extends Spiritix\LadaCache\Database\Model {
    //
}
```

It's a good practice to create a base model class which extends the Lada Cache model and then will be extended by all your models.

## Configuration

Todo

## Contributing

Bug reports, feature requests and contributions are welcome.
Please consider the following guidelines before submitting pull requests:

- Coding style must be followed (mostly PSR-2 with some differences)
- Tests must be submitted (100% coverage, all tests passing)
- Issue number must be referenced in PR

## License

Lada Cache is free software distributed under the terms of the MIT license.
