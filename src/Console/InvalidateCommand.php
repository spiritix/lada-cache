<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Invalidate the cached entries of specific tables.
 *
 * Meant for changes made outside Laravel's database connection, such as imports
 * or manual fixes, which would otherwise require flushing the whole cache.
 */
final class InvalidateCommand extends Command
{
    protected $signature = 'lada-cache:invalidate
        {tables* : Tables whose cached entries should be invalidated}
        {--connection= : Database connection of the tables (defaults to the default connection)}';

    protected $description = 'Invalidate the Lada Cache entries of specific tables.';

    public function handle(): int
    {
        if (! (bool) config('lada-cache.active', true)) {
            $this->error('Lada Cache is disabled. Nothing was invalidated.');

            return self::FAILURE;
        }

        try {
            $connection = DB::connection($this->option('connection'));
            $tables = array_values(array_unique((array) $this->argument('tables')));
            $schema = $connection->getSchemaBuilder();
            $unknown = array_values(array_filter($tables, static fn (string $table): bool => ! $schema->hasTable($table)));

            if ($unknown !== []) {
                $this->error("Unknown tables on connection [{$connection->getName()}]: ".implode(', ', $unknown));

                return self::FAILURE;
            }

            /** @var \Spiritix\LadaCache\QueryHandler $handler */
            $handler = app('lada.handler');
            $keys = $handler->invalidateTables($connection, $tables);
        } catch (Throwable $e) {
            $this->error('Failed to invalidate Lada Cache: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Invalidated %d cached entries of: %s', count($keys), implode(', ', $tables)));

        return self::SUCCESS;
    }
}
