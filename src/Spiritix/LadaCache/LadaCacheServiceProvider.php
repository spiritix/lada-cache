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
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Database\Connection\MysqlConnection;
use Spiritix\LadaCache\Database\Connection\PostgresConnection;
use Spiritix\LadaCache\Database\Connection\SqlLiteConnection;
use Spiritix\LadaCache\Database\Connection\SqlServerConnection;

/**
 * Laravel service provider for Lada Cache.
 *
 * @package Spiritix\LadaCache
 * @author  Matthias Isler <mi@matthias-isler.ch>
 */
class LadaCacheServiceProvider extends ServiceProvider
{
    /**
     * The package configuration file.
     */
    const CONFIG_FILE = 'lada-cache.php';

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
            __DIR__ . '/../../../config/' . self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ]);

        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/' . self::CONFIG_FILE, str_replace('.php', '', self::CONFIG_FILE)
        );
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->registerSingleton();
        $this->registerConnections();
        $this->registerCommand();
    }

    /**
     * Registers the cache manager in the IoC container.
     */
    private function registerSingleton()
    {
        $this->app->singleton('LadaCache', function() {
            return new Manager();
        });
    }

    /**
     * Register connections.
     *
     * Here we are overriding all connection singleton's from Laravel.
     * This is the only way to make them use our custom query builder which then uses our cache.
     */
    private function registerConnections()
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

    /**
     * Register custom commands.
     */
    private function registerCommand()
    {
        $this->app['command.lada-cache.flush'] = $this->app->share(function() {
            return new FlushCommand();
        });

        $this->app['command.lada-cache.enable'] = $this->app->share(function() {
            return new EnableCommand();
        });

        $this->app['command.lada-cache.disable'] = $this->app->share(function() {
            return new DisableCommand();
        });

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
        ]);
    }
}