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
use Spiritix\LadaCache\Connection\MysqlConnection;
use Spiritix\LadaCache\Connection\PostgresConnection;
use Spiritix\LadaCache\Connection\SqlLiteConnection;
use Spiritix\LadaCache\Connection\SqlServerConnection;

/**
 * Todo
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

        $this->registerModelObservers();
    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        $this->app->singleton('db.connection.mysql', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new MysqlConnection($connection, $database, $prefix, $config);
        });

        $this->app->singleton('db.connection.postgres', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new PostgresConnection($connection, $database, $prefix, $config);
        });

        $this->app->singleton('db.connection.sqllite', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new SqlLiteConnection($connection, $database, $prefix, $config);
        });

        $this->app->singleton('db.connection.sqlserver', function ($app, $parameters) {
            list($connection, $database, $prefix, $config) = $parameters;
            return new SqlServerConnection($connection, $database, $prefix, $config);
        });
    }

    /**
     * Registers the model observers.
     */
    private function registerModelObservers()
    {
        Model::created(function($model) {
            return true;
        });

        Model::updated(function($model) {
            return true;
        });

        Model::deleted(function($model) {
            return true;
        });

        Model::saved(function($model) {
            return true;
        });
    }
}