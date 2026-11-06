<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Fuzz\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\Database\Models\Car;

/**
 * Seeded random workload of reads, writes, transactions and cache disturbances against the
 * test schema. Reads compare the cached result with the same query run without the cache, so
 * any stale or wrong result fails with the seed and the operations that led to it.
 */
final class Workload
{
    private const array NAMES = ['a', 'b', 'c'];

    private const int MAX_ID = 8;

    private const int MAX_READ_ID = 2 * self::MAX_ID + 4;

    private const int HISTORY_LENGTH = 60;

    private const int PROBES_PER_WRITE = 6;

    private readonly Randomizer $random;

    /** @var list<string> */
    private array $history = [];

    private int $comparisons = 0;

    public function __construct(
        public readonly int $seed,
        private readonly string $driver,
        private readonly bool $allowCacheDisturbances = true,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
    }

    public static function seedFromEnvironment(): int
    {
        $seed = getenv('LADA_FUZZ_SEED');

        return $seed === false || $seed === '' ? random_int(1, PHP_INT_MAX) : (int) $seed;
    }

    public static function iterationsFromEnvironment(int $default): int
    {
        $iterations = getenv('LADA_FUZZ_ITERATIONS');

        return $iterations === false || $iterations === '' ? $default : max(1, (int) $iterations);
    }

    public function seedData(): void
    {
        foreach (range(1, 4) as $id) {
            DB::table('owners')->insert(['id' => $id, 'name' => $this->name()]);
            DB::table('drivers')->insert(['id' => $id, 'name' => $this->name()]);
        }
        foreach (range(1, 5) as $id) {
            DB::table('pets')->insert(['id' => $id, 'owner_id' => $this->random->getInt(1, 4), 'name' => $this->name()]);
            DB::table('cars')->insert(['id' => $id, 'name' => $this->name(), 'driver_id' => $this->random->getInt(1, 4), 'engine_id' => null]);
        }
        foreach (range(1, 3) as $id) {
            DB::table('pet_toys')->insert(['id' => $id, 'pet_id' => $this->random->getInt(1, 5), 'name' => $this->name()]);
            DB::table('owner_notes')->insert(['id' => $id, 'owner_id' => $this->random->getInt(1, 4), 'body' => $this->name()]);
        }
        DB::table('owner_audits')->insert(['id' => 1, 'owner_id' => 4, 'event' => 'signup']);

        if ($this->driver === 'pgsql') {
            foreach (['owners', 'drivers', 'pets', 'cars', 'pet_toys', 'owner_notes', 'owner_audits'] as $table) {
                DB::statement("select setval(pg_get_serial_sequence('{$table}', 'id'), 100)");
            }
        }
    }

    /**
     * Runs one random operation, comparing every read against the database.
     *
     * @param  bool  $compareReads  false while other processes write concurrently
     */
    public function step(bool $compareReads = true): void
    {
        $roll = $this->random->getInt(1, 100);

        try {
            match (true) {
                $roll <= 20 => $this->read($compareReads),
                $roll <= 85 => $this->writeBetweenProbes($compareReads),
                $roll <= 93 => $this->transactionControl(),
                default => $this->disturb(),
            };
        } catch (QueryException $exception) {
            $this->log('database rejected the statement: '.strtok($exception->getMessage(), "\n"));

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
                $this->log('rolled back every open transaction');
            }
        }
    }

    public function finish(): void
    {
        while (DB::transactionLevel() > 0) {
            $this->log('commit at end of run');
            DB::commit();
        }
    }

    /**
     * Compares every read shape for every id against the database.
     */
    public function sweep(): void
    {
        foreach ($this->readShapes() as $label => $shapeFor) {
            foreach (range(1, self::MAX_READ_ID) as $id) {
                $this->compare("sweep {$label} #{$id}", $shapeFor($id, self::NAMES[$id % 3]));
            }
        }
    }

    public function comparisons(): int
    {
        return $this->comparisons;
    }

    private function read(bool $compare): void
    {
        [$label, $query] = $this->probe();

        if ($compare) {
            $this->compare($label, $query);
        } else {
            $query(true);
        }
    }

    /**
     * Caches a few random reads, writes, then reads them again, so a write that misses an
     * invalidation shows up as a stale read of exactly the entries it should have retired.
     */
    private function writeBetweenProbes(bool $compare): void
    {
        $probes = array_map(fn (): array => $this->probe(), range(1, self::PROBES_PER_WRITE));

        foreach ($probes as [$label, $query]) {
            $compare ? $this->compare($label, $query) : $query(true);
        }

        $this->write();

        foreach ($probes as [$label, $query]) {
            $compare ? $this->compare("{$label} after write", $query) : $query(true);
        }
    }

    /**
     * @return array{0: string, 1: Closure(bool): mixed}
     */
    private function probe(): array
    {
        $shapes = $this->readShapes();
        $label = $this->pick(array_keys($shapes));
        $id = $this->random->getInt(1, self::MAX_READ_ID);
        $name = $this->name();
        $this->log("read {$label} id={$id} name={$name}");

        return ["{$label} id={$id} name={$name}", $shapes[$label]($id, $name)];
    }

    /**
     * Each shape builds the same query twice: through the cache and with withoutCache().
     *
     * @return array<string, Closure(int, string): Closure(bool): mixed>
     */
    private function readShapes(): array
    {
        $rows = static fn (Closure $build): Closure => static fn (bool $cached): array => array_map(
            static fn (object|array $row): array => (array) $row,
            ($cached ? $build() : $build()->withoutCache())->get()->all(),
        );
        $scalar = static fn (Closure $build, Closure $terminal): Closure => static fn (bool $cached): mixed => $terminal($cached ? $build() : $build()->withoutCache());

        return [
            'pet by id' => fn (int $id) => $rows(fn () => DB::table('pets')->where('id', $id)),
            'owner by id' => fn (int $id) => $rows(fn () => DB::table('owners')->where('id', $id)),
            'toy by id' => fn (int $id) => $rows(fn () => DB::table('pet_toys')->where('id', $id)),
            'note by id' => fn (int $id) => $rows(fn () => DB::table('owner_notes')->where('id', $id)),
            'pets in ids' => fn (int $id) => $rows(fn () => DB::table('pets')->whereIn('id', [$id, $id + 1])->orderBy('id')),
            'pets above id' => fn (int $id) => $rows(fn () => DB::table('pets')->where('id', '>', $id)->orderBy('id')),
            'pets by name count' => fn (int $id, string $name) => $scalar(fn () => DB::table('pets')->where('name', $name), static fn (Builder $query): int => $query->count()),
            'owners or' => fn (int $id) => $rows(fn () => DB::table('owners')->where('id', $id)->orWhere('id', $id + 2)->orderBy('id')),
            'owners not' => fn (int $id) => $rows(fn () => DB::table('owners')->whereNot('id', $id)->orderBy('id')),
            'pets join owners' => fn (int $id) => $rows(fn () => DB::table('pets')->join('owners', 'owners.id', '=', 'pets.owner_id')->where('owners.id', $id)->orderBy('pets.id')->select('pets.id', 'owners.name')),
            'owners left join notes' => fn (int $id) => $rows(fn () => DB::table('owners')->leftJoin('owner_notes', 'owner_notes.owner_id', '=', 'owners.id')->where('owners.id', $id)->orderBy('owner_notes.id')->select('owners.id', 'owner_notes.body')),
            'pets where owner in subquery' => fn (int $id, string $name) => $rows(fn () => DB::table('pets')->whereIn('owner_id', DB::table('owners')->select('id')->where('name', $name))->orderBy('id')),
            'owners with pets' => fn (int $id) => $rows(fn () => DB::table('owners')->whereExists(fn (Builder $query) => $query->select(DB::raw(1))->from('pets')->whereColumn('pets.owner_id', 'owners.id'))->orderBy('id')),
            'pet counts from sub' => fn (int $id) => $rows(fn () => DB::query()->fromSub(DB::table('pets')->select('owner_id', DB::raw('count(*) as pet_count'))->groupBy('owner_id'), 'counts')->orderBy('owner_id')),
            'owners with pet count' => fn (int $id) => $rows(fn () => DB::table('owners')->select('id')->selectSub(DB::table('pets')->selectRaw('count(*)')->whereColumn('pets.owner_id', 'owners.id'), 'pet_count')->orderBy('id')),
            'owners join toy counts' => fn (int $id) => $rows(fn () => DB::table('owners')->joinSub(DB::table('pets')->join('pet_toys', 'pet_toys.pet_id', '=', 'pets.id')->select('pets.owner_id', DB::raw('count(*) as toy_count'))->groupBy('pets.owner_id'), 'toys', 'toys.owner_id', '=', 'owners.id')->orderBy('owners.id')->select('owners.id', 'toys.toy_count')),
            'names union' => fn (int $id) => $rows(fn () => DB::table('owners')->select('name')->union(DB::table('pets')->select('name'))->orderBy('name')),
            'pets between' => fn (int $id) => $rows(fn () => DB::table('pets')->whereBetween('id', [$id, $id + 2])->orderBy('id')),
            'max pet id' => fn () => $scalar(fn () => DB::table('pets'), static fn (Builder $query): mixed => $query->max('id')),
            'sum toy pet ids' => fn () => $scalar(fn () => DB::table('pet_toys'), static fn (Builder $query): int => (int) $query->sum('pet_id')),
            'pet exists' => fn (int $id) => $scalar(fn () => DB::table('pets')->where('id', $id), static fn (Builder $query): bool => $query->exists()),
            'owner names' => fn () => $scalar(fn () => DB::table('owners')->orderBy('id'), static fn (Builder $query): array => $query->pluck('name')->all()),
            'owners by pet count' => fn () => $rows(fn () => DB::table('owners')->select('id')->orderByDesc(DB::table('pets')->selectRaw('count(*)')->whereColumn('pets.owner_id', 'owners.id'))->orderBy('id')),
            'toys join pets join owners' => fn (int $id) => $rows(fn () => DB::table('pet_toys')->join('pets', function (JoinClause $join) use ($id): void {
                $join->on('pets.id', '=', 'pet_toys.pet_id')->whereIn('pets.owner_id', DB::table('owners')->select('id')->where('id', '<=', $id));
            })->orderBy('pet_toys.id')->select('pet_toys.id', 'pets.owner_id')),
            'car model by id' => fn (int $id) => static fn (bool $cached): array => ($cached ? Car::query() : Car::query()->withoutCache())->whereKey($id)->get(['id', 'name', 'driver_id'])->toArray(),
            'car models find many' => fn (int $id) => static fn (bool $cached): array => ($cached ? Car::query() : Car::query()->withoutCache())->findMany([$id, $id + 1], ['id', 'name'])->sortBy('id')->values()->toArray(),
            'car model count' => fn () => static fn (bool $cached): int => ($cached ? Car::query() : Car::query()->withoutCache())->count(),
            'cars of driver' => fn (int $id) => $rows(fn () => DB::table('cars')->where('driver_id', $id)->whereNull('deleted_at')->orderBy('id')->select('id', 'name')),
        ];
    }

    private function write(): void
    {
        $writes = [
            'insert owner' => fn () => DB::table('owners')->insert($this->maybeWithId(['name' => $this->name()])),
            'insert pet' => fn () => DB::table('pets')->insert($this->maybeWithId(['owner_id' => $this->existingId('owners'), 'name' => $this->name()])),
            'insert toy get id' => fn () => DB::table('pet_toys')->insertGetId(['pet_id' => $this->existingId('pets'), 'name' => $this->name()]),
            'insert or ignore owner' => fn () => DB::table('owners')->insertOrIgnore(['id' => $this->id(), 'name' => $this->name()]),
            'upsert owner' => fn () => DB::table('owners')->upsert([['id' => $this->id(), 'name' => $this->name()]], ['id'], ['name']),
            'update owner name' => fn () => DB::table('owners')->where('id', $this->id())->update(['name' => $this->name()]),
            'update pets above id' => fn () => DB::table('pets')->where('id', '>', $this->id())->update(['name' => $this->name()]),
            'move pet to owner' => fn () => DB::table('pets')->where('id', $this->id())->update(['owner_id' => $this->existingId('owners')]),
            'change owner key' => fn () => DB::table('owners')->where('id', $this->id())->update(['id' => $this->id() + self::MAX_ID]),
            'delete owner' => fn () => DB::table('owners')->where('id', $this->id())->delete(),
            'delete pets range' => fn () => DB::table('pets')->whereBetween('id', [$this->id(), $this->id() + 1])->delete(),
            'delete note' => fn () => DB::table('owner_notes')->where('id', $this->id())->delete(),
            'rename toy' => fn () => DB::table('pet_toys')->where('id', $this->id())->update(['name' => $this->name()]),
            'raw update pet' => fn () => DB::update('update pets set name = ? where id = ?', [$this->name(), $this->id()]),
            'raw insert note' => fn () => DB::insert('insert into owner_notes (owner_id, body) values (?, ?)', [$this->existingId('owners'), $this->name()]),
            'raw delete toy' => fn () => DB::delete('delete from pet_toys where id = ?', [$this->id()]),
            'update or insert owner' => fn () => DB::table('owners')->updateOrInsert(['id' => $this->id()], ['name' => $this->name()]),
            'create car model' => fn () => Car::query()->create(['name' => $this->name(), 'driver_id' => $this->existingId('drivers')]),
            'update car model' => fn () => Car::query()->withoutCache()->find($this->id())?->update(['name' => $this->name()]),
            'soft delete car model' => fn () => Car::query()->withoutCache()->find($this->id())?->delete(),
            'update driver' => fn () => DB::table('drivers')->where('id', $this->id())->update(['name' => $this->name()]),
            'truncate toys' => fn () => DB::transactionLevel() === 0 ? DB::table('pet_toys')->truncate() : null,
        ];

        $label = $this->pick(array_keys($writes));
        $this->log("write {$label}");
        $writes[$label]();
    }

    private function transactionControl(): void
    {
        $level = DB::transactionLevel();
        $choice = $this->random->getInt(1, 3);

        if ($level === 0 || ($choice === 1 && $level < 2)) {
            $this->log('begin transaction');
            DB::beginTransaction();

            return;
        }

        if ($choice === 2) {
            $this->log('commit');
            DB::commit();

            return;
        }

        $this->log('rollback');
        DB::rollBack();
    }

    private function disturb(): void
    {
        if (! $this->allowCacheDisturbances) {
            $this->write();

            return;
        }

        $redis = app('lada.redis');
        assert($redis instanceof Redis);
        $connection = RedisFacade::connection((string) config('lada-cache.redis_connection'));

        match ($this->random->getInt(1, 3)) {
            1 => (function (): void {
                $this->log('flush cache');
                app('lada.cache')->flush();
            })(),
            2 => (function () use ($connection): void {
                $this->log('empty redis');
                $connection->flushdb();
            })(),
            default => (function () use ($redis, $connection): void {
                $keys = $connection->keys($redis->prefix('*'));
                $evicted = array_values(array_filter($keys, fn (): bool => $this->random->getInt(1, 3) === 1));
                $this->log('evict '.count($evicted).' of '.count($keys).' keys');

                foreach ($evicted as $key) {
                    $connection->del($this->withoutConnectionPrefix((string) $key));
                }
            })(),
        };
    }

    /**
     * @param  Closure(bool): mixed  $query
     */
    private function compare(string $label, Closure $query): void
    {
        $cached = $query(true);
        $actual = $query(false);
        $this->comparisons++;

        if ($cached === $actual) {
            return;
        }

        throw new StaleReadException(sprintf(
            "Cached result of \"%s\" differs from the database.\nSeed: %d (rerun with LADA_FUZZ_SEED=%d)\nCached: %s\nDatabase: %s\nLast operations:\n  %s",
            $label,
            $this->seed,
            $this->seed,
            json_encode($cached),
            json_encode($actual),
            implode("\n  ", $this->history),
        ));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function maybeWithId(array $row): array
    {
        return $this->random->getInt(0, 1) === 1 ? ['id' => $this->id() + self::MAX_ID, ...$row] : $row;
    }

    private function existingId(string $table): int
    {
        $ids = DB::table($table)->withoutCache()->orderBy('id')->pluck('id')->all();

        return $ids === [] ? $this->id() : (int) $this->pick($ids);
    }

    private function id(): int
    {
        return $this->random->getInt(1, self::MAX_ID);
    }

    private function name(): string
    {
        return $this->pick(self::NAMES);
    }

    /**
     * @template TValue
     *
     * @param  list<TValue>  $values
     * @return TValue
     */
    private function pick(array $values): mixed
    {
        return $values[$this->random->getInt(0, count($values) - 1)];
    }

    private function withoutConnectionPrefix(string $key): string
    {
        $prefix = (string) (config('database.redis.options.prefix') ?: '');

        return $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key;
    }

    private function log(string $operation): void
    {
        $this->history[] = sprintf('[%d] %s', DB::transactionLevel(), $operation);

        if (count($this->history) > self::HISTORY_LENGTH) {
            array_shift($this->history);
        }
    }
}
