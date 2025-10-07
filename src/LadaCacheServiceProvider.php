<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Database\ConnectionDecorator;
use Spiritix\LadaCache\Debug\CacheCollector;

/**
 * Lada Cache service provider for Laravel 12.
 *
 * Registers bindings, database connection decorators, artisan commands,
 * and optional Debugbar integration. Configuration publishing is provided
 * and the package's config is merged during registration.
 */
final class LadaCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_FILE = 'lada-cache.php';

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/' . self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ], 'config');

        if (config('lada-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }
    }

    /**
     * @inheritDoc
     */
    public function register()
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/' . self::CONFIG_FILE,
            'lada-cache'
        );

        $this->registerSingletons();
        $this->registerDatabaseDecorator();
        $this->registerCommands();
    }

    /**
     * @inheritDoc
     */
    public function provides()
    {
        return [
            'lada.redis',
            'lada.cache',
            'lada.invalidator',
            'lada.handler',
        ];
    }

    private function registerSingletons(): void
    {
        $this->app->singleton('lada.redis', static fn() => new Redis());

        $this->app->singleton('lada.cache', static fn(Application $app) =>
            new Cache($app->make('lada.redis'), new Encoder())
        );

        $this->app->singleton('lada.invalidator', static fn(Application $app) =>
            new Invalidator($app->make('lada.redis'))
        );

        $this->app->singleton('lada.handler', static fn(Application $app) =>
            new QueryHandler($app->make('lada.cache'), $app->make('lada.invalidator'))
        );
    }

    private function registerDatabaseDecorator(): void
    {
        foreach (['mysql', 'pgsql', 'sqlite', 'sqlsrv'] as $driver) {
            DB::extend($driver, static function (array $config, string $name): ConnectionDecorator {
                $connection = app('db.factory')->make($config, $name);
                return new ConnectionDecorator($connection);
            });
        }
    }

    private function registerCommands(): void
    {
        $this->app->singleton('command.lada-cache.flush', static fn() => new FlushCommand());
        $this->app->singleton('command.lada-cache.enable', static fn() => new EnableCommand());
        $this->app->singleton('command.lada-cache.disable', static fn() => new DisableCommand());

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
        ]);
    }

    private function registerDebugbarCollector(): void
    {
        $this->app->singleton('lada.collector', static fn() => new CacheCollector());
        $this->app->make('debugbar')->addCollector($this->app->make('lada.collector'));
    }
}
