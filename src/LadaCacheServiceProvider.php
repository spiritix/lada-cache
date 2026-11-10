<?php

declare(strict_types=1);

namespace Spiritix\LadaCache;

use DebugBar\DebugBar;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use Spiritix\LadaCache\Console\DisableCommand;
use Spiritix\LadaCache\Console\EnableCommand;
use Spiritix\LadaCache\Console\FlushCommand;
use Spiritix\LadaCache\Console\InvalidateCommand;
use Spiritix\LadaCache\Database\MariaDbConnection as LadaMariaDbConnection;
use Spiritix\LadaCache\Database\MySqlConnection as LadaMySqlConnection;
use Spiritix\LadaCache\Database\PostgresConnection as LadaPostgresConnection;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Database\SqliteConnection as LadaSqliteConnection;
use Spiritix\LadaCache\Database\SqlServerConnection as LadaSqlServerConnection;
use Spiritix\LadaCache\Debug\CacheCollector;
use Spiritix\LadaCache\Support\CacheOperation;
use Spiritix\LadaCache\Support\ConfigValue;
use Spiritix\LadaCache\Support\DependentTables;
use Spiritix\LadaCache\Support\FailedInvalidations;
use Spiritix\LadaCache\Support\FailureReporter;
use Spiritix\LadaCache\Support\FrameworkTables;
use Spiritix\LadaCache\Support\RedisCooldown;
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

    private const array SINGLETONS = [Services::REDIS, Services::CACHE, Services::HANDLER];

    private const array LADA_CONNECTIONS = [
        'mysql' => LadaMySqlConnection::class,
        'mariadb' => LadaMariaDbConnection::class,
        'pgsql' => LadaPostgresConnection::class,
        'sqlite' => LadaSqliteConnection::class,
        'sqlsrv' => LadaSqlServerConnection::class,
    ];

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
            /** @var EloquentBuilder<Model> $this */
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
            ...ConfigValue::strings('lada-cache.exclude_tables'),
            ...FrameworkTables::volatile(),
        ]))]);

        if (config('lada-cache.enable_debugbar') && $this->app->bound('debugbar')) {
            $this->registerDebugbarCollector();
        }

        $events = $this->app->make(Dispatcher::class);
        $events->listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            Services::handler()->transactionCommitted($event->connection);
        });
        $events->listen(TransactionRolledBack::class, static function (TransactionRolledBack $event): void {
            Services::handler()->transactionRolledBack($event->connection);
        });

        $events->listen(MigrationEnded::class, function (MigrationEnded $event): void {
            Services::handler()->schemaChanged($this->app->make(Migrator::class)->resolveConnection($event->migration->getConnection()));
        });
        $events->listen([SchemaLoaded::class, DatabaseRefreshed::class], static function (SchemaLoaded|DatabaseRefreshed $event): void {
            Services::handler()->schemaChanged(
                $event instanceof SchemaLoaded ? $event->connection : DB::connection($event->database)
            );
        });
        $events->listen(MigrationsEnded::class, static function (): void {
            try {
                Services::cache()->pruneRetiredGenerations();
            } catch (Throwable $exception) {
                FailureReporter::report(CacheOperation::Prune, $exception);
            }
        });

        $this->app->terminating(function (): void {
            if ($this->app->resolved(Services::HANDLER)) {
                Services::handler()->replayFailedInvalidations(RedisCooldown::Ignore);
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
            CacheScope::fromConfig();
            ConfigValue::strings('lada-cache.include_tables');
            ConfigValue::strings('lada-cache.exclude_tables');
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
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return self::SINGLETONS;
    }

    private function registerSingletons(): void
    {
        $this->app->singleton(Services::REDIS, static fn (): Redis => new Redis);
        $this->app->singleton(Services::CACHE, static fn (): Cache => new Cache(Services::redis(), new Encoder));
        $this->app->singleton(Services::HANDLER, static fn (): QueryHandler => new QueryHandler(
            Services::cache(),
            FailedInvalidations::detect(Services::redis()->processStateKey('failed'), ExpirationPolicy::fromConfig()->seconds),
        ));
    }

    /**
     * Copy driver-specific state from the base connection to the Lada connection.
     */
    private static function hydrateLadaConnection(Connection $base, Connection $lada): Connection
    {
        $lada->setReadPdo($base->getRawReadPdo());
        $lada->setQueryGrammar($base->getQueryGrammar());
        $lada->setPostProcessor($base->getPostProcessor());

        $base->getSchemaBuilder();
        $lada->setSchemaGrammar($base->getSchemaGrammar());

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

        config(['octane.warm' => array_values(array_unique([...ConfigValue::strings('octane.warm'), ...self::SINGLETONS]))]);
    }

    private function registerDatabaseDecorator(): void
    {
        foreach (self::LADA_CONNECTIONS as $driver => $class) {
            DB::extend($driver, static fn (array $config, string $name): Connection => self::ladaConnection($class, $config, $name));
        }
    }

    /**
     * @param  class-string<LadaMySqlConnection|LadaMariaDbConnection|LadaPostgresConnection|LadaSqliteConnection|LadaSqlServerConnection>  $class
     * @param  array<mixed>  $config
     */
    private static function ladaConnection(string $class, array $config, string $name): Connection
    {
        $base = Services::make('db.factory', ConnectionFactory::class)->make($config, $name);
        $baseConfig = $base->getConfig();

        return self::hydrateLadaConnection($base, new $class(
            $base->getRawPdo() ?? throw new LogicException("Database connection {$name} has no PDO to share with Lada Cache"),
            $base->getDatabaseName(),
            $base->getTablePrefix(),
            is_array($baseConfig) ? $baseConfig : throw new LogicException("Database connection {$name} has no configuration array"),
        ));
    }

    private function registerCommands(): void
    {
        $this->app->singleton('command.lada-cache.flush', static fn () => new FlushCommand);
        $this->app->singleton('command.lada-cache.enable', static fn () => new EnableCommand);
        $this->app->singleton('command.lada-cache.disable', static fn () => new DisableCommand);
        $this->app->singleton('command.lada-cache.invalidate', static fn () => new InvalidateCommand);

        $this->commands([
            'command.lada-cache.flush',
            'command.lada-cache.enable',
            'command.lada-cache.disable',
            'command.lada-cache.invalidate',
        ]);
    }

    private function registerDebugbarCollector(): void
    {
        $this->app->singleton(Services::COLLECTOR, static fn (): CacheCollector => new CacheCollector);
        Services::make('debugbar', DebugBar::class)->addCollector(Services::collector() ?? throw new LogicException('Lada Cache collector was not registered'));
    }
}
