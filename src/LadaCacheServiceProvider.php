<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Database\MySqlConnection as LadaMySqlConnection;
use Spiritix\LadaCache\Database\MariaDbConnection as LadaMariaDbConnection;
use Spiritix\LadaCache\Database\PostgresConnection as LadaPostgresConnection;
use Spiritix\LadaCache\Database\SqliteConnection as LadaSqliteConnection;
use Spiritix\LadaCache\Database\SqlServerConnection as LadaSqlServerConnection;
use Spiritix\LadaCache\Debug\CacheCollector;

/**
 * Lada Cache service provider for Laravel.
 *
 * Registers bindings, database connection integration (via DB::extend()),
 * artisan commands, and optional Debugbar integration. Configuration
 * publishing is provided and the package's config is merged during registration.
 */
final class LadaCacheServiceProvider extends ServiceProvider
{
    public const CONFIG_FILE = 'lada-cache.php';

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/'.self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ], 'config');

        // If Lada Cache is not active, avoid wiring listeners / debugbar that could resolve Redis.
        if (! (bool) config('lada-cache.active', true)) {
            return;
        }

        if (config('lada-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }

        // Register transaction event listeners to coordinate transaction-aware invalidations.
        $events = $this->app['events'];
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            /** @var QueryHandler $handler */
            $handler = $this->app->make('lada.handler');
            $handler->flushQueuedInvalidationsForConnection($event->connection);
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            /** @var QueryHandler $handler */
            $handler = $this->app->make('lada.handler');
            $handler->clearQueuedInvalidationsForConnection($event->connection);
        });

        // Auto-flush cache after migrations complete to prevent stale schema-related cache.
        $events->listen(MigrationsEnded::class, function (): void {
            /** @var Cache $cache */
            $cache = $this->app->make('lada.cache');
            $cache->flush();
        });
    }

    /**
     * {@inheritDoc}
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/'.self::CONFIG_FILE,
            'lada-cache'
        );

        // Only register when active to avoid bootstrap overhead when disabled
        if ((bool) config('lada-cache.active', true)) {
            $this->registerSingletons();
            $this->registerDatabaseDecorator();
        }

        $this->registerCommands();
    }

    /**
     * {@inheritDoc}
     */
    public function provides(): array
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
        $this->app->singleton('lada.redis', static fn () => new Redis);

        $this->app->singleton('lada.cache', static fn (Application $app) => new Cache($app->make('lada.redis'), new Encoder)
        );

        $this->app->singleton('lada.invalidator', static fn (Application $app) => new Invalidator($app->make('lada.redis'))
        );

        $this->app->singleton('lada.handler', static fn (Application $app) => new QueryHandler($app->make('lada.cache'), $app->make('lada.invalidator'))
        );
    }

    /**
     * Copy driver-specific state from the base connection to the Lada connection.
     */
    private function hydrateLadaConnection(\Illuminate\Database\Connection $base, \Illuminate\Database\Connection $lada, string $name): \Illuminate\Database\Connection
    {
        if (method_exists($lada, 'setReadPdo')) {
            $lada->setReadPdo($base->getReadPdo());
        }
        if (method_exists($lada, 'setName')) {
            $lada->setName($name);
        }

        $lada->setQueryGrammar($base->getQueryGrammar());
        $lada->setPostProcessor($base->getPostProcessor());

        // Initialize schema grammar on base, then mirror it
        $base->getSchemaBuilder();
        if (method_exists($lada, 'setSchemaGrammar') && $base->getSchemaGrammar() !== null) {
            $lada->setSchemaGrammar($base->getSchemaGrammar());
        }

        return $lada;
    }

    private function registerDatabaseDecorator(): void
    {
        DB::extend('mysql', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\MySqlConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaMySqlConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );
            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        // Optional explicit MariaDB driver (alias of MySQL)
        DB::extend('mariadb', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\MySqlConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaMariaDbConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );
            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('pgsql', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\PostgresConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaPostgresConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );
            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('sqlite', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\SqliteConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaSqliteConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );
            return $this->hydrateLadaConnection($base, $lada, $name);
        });

        DB::extend('sqlsrv', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\SqlServerConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaSqlServerConnection(
                $base->getPdo(),
                $base->getDatabaseName(),
                $base->getTablePrefix(),
                $base->getConfig(),
            );
            return $this->hydrateLadaConnection($base, $lada, $name);
        });
    }

    private function registerCommands(): void
    {
        $this->app->singleton('command.lada-cache.flush', static fn () => new FlushCommand);
        $this->app->singleton('command.lada-cache.enable', static fn () => new EnableCommand);
        $this->app->singleton('command.lada-cache.disable', static fn () => new DisableCommand);

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
        ]);
    }

    private function registerDebugbarCollector(): void
    {
        $this->app->singleton('lada.collector', static fn () => new CacheCollector);
        $this->app->make('debugbar')->addCollector($this->app->make('lada.collector'));
    }
}
