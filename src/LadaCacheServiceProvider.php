<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Database\MySqlConnection as LadaMySqlConnection;
use Spiritix\LadaCache\Database\MariaDbConnection as LadaMariaDbConnection;
use Spiritix\LadaCache\Database\PostgresConnection as LadaPostgresConnection;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Database\SqliteConnection as LadaSqliteConnection;
use Spiritix\LadaCache\Database\SqlServerConnection as LadaSqlServerConnection;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\FailedInvalidations;
use Spiritix\LadaCache\Support\FailureReporter;
use Spiritix\LadaCache\Support\FrameworkTables;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\ReplicaReads;
use Throwable;

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

    private const array SINGLETONS = ['lada.redis', 'lada.cache', 'lada.handler'];

    private const array RENAMED_KEYS = [
        'expiration-time' => 'expiration_time',
        'consider-rows' => 'consider_rows',
        'include-tables' => 'include_tables',
        'exclude-tables' => 'exclude_tables',
        'enable-debugbar' => 'enable_debugbar',
    ];

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/'.self::CONFIG_FILE => config_path(self::CONFIG_FILE),
        ], 'config');

        // Propagate withoutCache() from the Eloquent builder to the underlying Lada query builder.
        // Registered outside the active guard so the call is a no-op when Lada is disabled.
        EloquentBuilder::macro('withoutCache', function () {
            /** @var EloquentBuilder $this */
            $query = $this->getQuery();

            if ($query instanceof QueryBuilder) {
                $query->withoutCache();
            }

            return $this;
        });

        // If Lada Cache is not active, avoid wiring listeners / debugbar that could resolve Redis.
        if (! (bool) config('lada-cache.active', true)) {
            return;
        }

        $this->warmSingletonsUnderOctane();

        config(['lada-cache.exclude_tables' => array_values(array_unique([
            ...(array) config('lada-cache.exclude_tables', []),
            ...FrameworkTables::volatile(),
        ]))]);

        if (config('lada-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }

        $events = $this->app['events'];
        $events->listen(TransactionCommitted::class, function (TransactionCommitted $event): void {
            $this->app->make('lada.handler')->transactionCommitted($event->connection);
        });
        $events->listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $this->app->make('lada.handler')->transactionRolledBack($event->connection);
        });

        $events->listen(MigrationEnded::class, function (MigrationEnded $event): void {
            $this->app->make('lada.handler')->schemaChanged($this->app->make('migrator')->resolveConnection($event->migration->getConnection()));
        });
        $events->listen([SchemaLoaded::class, DatabaseRefreshed::class], function (SchemaLoaded|DatabaseRefreshed $event): void {
            $this->app->make('lada.handler')->schemaChanged(
                $event instanceof SchemaLoaded ? $event->connection : DB::connection($event->database)
            );
        });
        $events->listen(MigrationsEnded::class, function (): void {
            try {
                $this->app->make('lada.cache')->pruneRetiredGenerations();
            } catch (Throwable $exception) {
                FailureReporter::report(CacheOperation::Prune, $exception);
            }
        });

        $this->app->terminating(function (): void {
            if ($this->app->resolved('lada.handler')) {
                $this->app->make('lada.handler')->replayFailedInvalidations();
            }
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
        if ((bool) config('lada-cache.active', true) && $this->hasValidConfiguration()) {
            $this->registerSingletons();
            $this->registerDatabaseDecorator();
        }

        $this->registerCommands();
    }

    private function hasValidConfiguration(): bool
    {
        try {
            self::rejectRenamedKeys();
            CacheScope::fromConfig(config('lada-cache.scope', CacheScope::All->value));
            ExpirationPolicy::fromConfig();
            EntrySizeLimit::fromConfig();
            ReplicaReads::configuredLagSeconds();
            DependentTables::configured();

            return true;
        } catch (InvalidArgumentException $exception) {
            Log::error('[LadaCache] Disabled because of invalid configuration: '.$exception->getMessage());
            config(['lada-cache.active' => false]);

            return false;
        }
    }

    /**
     * A configuration published by an earlier version names settings that no longer exist, so
     * its values would be silently replaced by defaults.
     */
    private static function rejectRenamedKeys(): void
    {
        $renamed = array_intersect_key(self::RENAMED_KEYS, (array) config('lada-cache', []));

        if ($renamed !== []) {
            throw new InvalidArgumentException(implode(', ', array_map(
                static fn (string $old, string $new): string => "lada-cache.{$old} was renamed to lada-cache.{$new}",
                array_keys($renamed),
                $renamed,
            )));
        }
    }

    /**
     * {@inheritDoc}
     */
    public function provides(): array
    {
        return self::SINGLETONS;
    }

    private function registerSingletons(): void
    {
        $this->app->singleton('lada.redis', static fn () => new Redis);

        $this->app->singleton('lada.cache', static fn (Application $app) => new Cache($app->make('lada.redis'), new Encoder)
        );

        $this->app->singleton('lada.handler', static function (Application $app): QueryHandler {
            $redis = $app->make('lada.redis');

            return new QueryHandler(
                $app->make('lada.cache'),
                FailedInvalidations::detect($redis->processStateKey('failed'), ExpirationPolicy::fromConfig()->seconds),
            );
        });
    }

    /**
     * Copy driver-specific state from the base connection to the Lada connection.
     */
    private function hydrateLadaConnection(\Illuminate\Database\Connection $base, \Illuminate\Database\Connection $lada, string $name): \Illuminate\Database\Connection
    {
        if (method_exists($lada, 'setReadPdo')) {
            $lada->setReadPdo($base->getRawReadPdo());
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

    /**
     * Octane, which also runs FrankenPHP worker mode, resolves services in a per-request copy of
     * the container unless they are warmed. Warming keeps the failure cooldown and invalidations
     * awaiting replay alive across requests, as they are meant to be in long-lived workers.
     */
    private function warmSingletonsUnderOctane(): void
    {
        if (! is_array(config('octane.warm'))) {
            return;
        }

        config(['octane.warm' => array_values(array_unique([...config('octane.warm'), ...self::SINGLETONS]))]);
    }

    private function registerDatabaseDecorator(): void
    {
        DB::extend('mysql', function (array $config, string $name): \Illuminate\Database\Connection {
            /** @var \Illuminate\Database\MySqlConnection $base */
            $base = app('db.factory')->make($config, $name);
            $lada = new LadaMySqlConnection(
                $base->getRawPdo(),
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
                $base->getRawPdo(),
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
                $base->getRawPdo(),
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
                $base->getRawPdo(),
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
                $base->getRawPdo(),
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
