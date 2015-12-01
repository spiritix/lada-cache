<?php
/**
 * This file is part of the spiritix/lada-cache package.
 *
 * @copyright Copyright (c) Matthias Isler <mi@matthias-isler.ch>
 * @license   MIT
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Spiritix\LadaCache;

use Illuminate\Support\ServiceProvider;
use Spiritix\LadaCache\Database\Connection\MysqlConnection;
use Spiritix\LadaCache\Database\Connection\PostgresConnection;
use Spiritix\LadaCache\Database\Connection\SqlLiteConnection;
use Spiritix\LadaCache\Database\Connection\SqlServerConnection;

/**
 * Laravel service provider for Lada Cache.
 *
 * Since Lada Cache can't be "used" manually, a cache service is not required.
 * We'll use this functionality to provide and publish the config as well as to override the default database connections.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class LadaCacheServiceProvider extends ServiceProvider
{
    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * Bootstrap the application events.
     */
    public function boot()
    {
        $this->publishes([
            __DIR__ . '/../../../config/lada-cache.php' => config_path('lada-cache.php'),
        ]);

        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/lada-cache.php', 'lada-cache'
        );
    }

    /**
     * Register the service provider.
     *
     * Here we are overriding all connection singleton's from Laravel.
     * This is the only way to make them use our custom query builder which then uses our cache.
     *
     * @todo Bind singleton to IoC container as soon as this issue is fixed: https://github.com/laravel/framework/issues/11122
     */
    public function register()
    {
        $this->app->bind('db.connection.mysql', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new MysqlConnection($connection, $database, $prefix, $config);
        });

        $this->app->bind('db.connection.postgres', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new PostgresConnection($connection, $database, $prefix, $config);
        });

        $this->app->bind('db.connection.sqllite', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new SqlLiteConnection($connection, $database, $prefix, $config);
        });

        $this->app->bind('db.connection.sqlserver', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new SqlServerConnection($connection, $database, $prefix, $config);
        });
    }
}