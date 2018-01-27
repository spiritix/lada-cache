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
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Database\Connection\MysqlConnection;
use Spiritix\LadaCache\Database\Connection\PostgresConnection;
use Spiritix\LadaCache\Database\Connection\SqlLiteConnection;
use Spiritix\LadaCache\Database\Connection\SqlServerConnection;
use Spiritix\LadaCache\Debug\CacheCollector;

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
        ], 'config');

        $this->mergeConfigFrom(
            __DIR__ . '/../../../config/' . self::CONFIG_FILE, str_replace('.php', '', self::CONFIG_FILE)
        );

        if ($this->app->offsetExists('debugbar')) {
            $this->registerDebugbarCollector();
        }
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->registerSingletons();
        $this->registerConnections();
        $this->registerCommand();
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return ['lada.cache', 'lada.invalidator'];
    }

    /**
     * Registers the cache services in the IoC container.
     */
    private function registerSingletons()
    {
        $this->app->singleton('lada.redis', function($app) {
            return new Redis();
        });

        $this->app->singleton('lada.cache', function($app) {
            return new Cache($app->make('lada.redis'), new Encoder());
        });

        $this->app->singleton('lada.invalidator', function($app) {
            return new Invalidator($app->make('lada.redis'));
        });

        $this->app->singleton('lada.handler', function($app) {
            return new QueryHandler($app->make('lada.cache'), $app->make('lada.invalidator'));
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
        $this->app->singleton('command.lada-cache.flush', function() {
            return new FlushCommand();
        });

        $this->app->singleton('command.lada-cache.enable', function() {
            return new EnableCommand();
        });

        $this->app->singleton('command.lada-cache.disable', function() {
            return new DisableCommand();
        });

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
        ]);
    }

    /**
     * Register our custom debugbar collector.
     */
    private function registerDebugbarCollector()
    {
        $this->app->singleton('lada.collector', function() {
            return new CacheCollector();
        });

        $debugBar = $this->app->make('debugbar');
        $debugBar->addCollector($this->app->make('lada.collector'));
    }
}
