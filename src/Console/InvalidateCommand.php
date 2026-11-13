<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Services;
use Spiritix\LadaCache\Support\RedisCooldown;
use Spiritix\LadaCache\Support\Write;
use Throwable;

/**
 * Invalidates the cached reads of specific tables, for changes made outside Laravel's database
 * connection such as imports or manual fixes, without retiring the whole cache.
 */
final class InvalidateCommand extends Command
{
    protected $signature = 'lada-cache:invalidate
        {tables* : Tables whose cached reads to invalidate}
        {--connection= : Database connection of the tables, the default connection when omitted}';

    protected $description = 'Invalidate the Lada Cache entries of specific tables.';

    public function handle(): int
    {
        if (! (bool) config('lada-cache.active', true)) {
            $this->error('Lada Cache is disabled. Nothing was invalidated.');

            return self::FAILURE;
        }

        try {
            $name = $this->option('connection');
            $connection = DB::connection(is_string($name) ? $name : null);
        } catch (Throwable $exception) {
            $this->error('Unknown database connection: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! $connection->query() instanceof QueryBuilder) {
            $this->error("Connection {$connection->getName()} does not use Lada Cache.");

            return self::FAILURE;
        }

        $tables = array_values(array_unique(array_filter((array) $this->argument('tables'), 'is_string')));
        $schema = $connection->getSchemaBuilder();
        $unknown = array_values(array_filter($tables, static fn (string $table): bool => ! $schema->hasTable($table)));

        if ($unknown !== []) {
            $this->error("Unknown tables on connection {$connection->getName()}: ".implode(', ', $unknown));

            return self::FAILURE;
        }

        $handler = Services::handler();
        $handler->invalidateTables($connection, $tables, Write::unknown());

        if (! $handler->replayFailedInvalidations(RedisCooldown::Ignore)) {
            $this->error('Redis rejected the invalidation; it is kept and replayed once Redis accepts it.');

            return self::FAILURE;
        }

        $this->info('Invalidated the cached reads of: '.implode(', ', $tables));

        return self::SUCCESS;
    }
}
