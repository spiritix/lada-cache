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
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\License;

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

    private const string ALIAS_CONNECTION = 'fuzz_alias';

    private const array LICENSE_CODES = ['ab-1', 'Cd-2', 'EF_3', 'gh-4', 'ij-5', 'Kl-6', 'MN_7', 'op-8'];

    private readonly Randomizer $random;

    /** @var list<string> */
    private array $history = [];

    private int $comparisons = 0;

    /** @var array<string, array{runs: int, rejected: int}> */
    private array $writeStatistics = [];

    private ?string $currentWrite = null;

    private readonly string $primaryConnection;

    /** @var list<string> */
    private array $connections;

    public function __construct(
        public readonly int $seed,
        private readonly string $driver,
        private readonly bool $allowCacheDisturbances = true,
    ) {
        $this->random = new Randomizer(new Mt19937($seed));
        $this->primaryConnection = (string) config('database.default');
        $this->connections = [$this->primaryConnection];

        if ($driver !== 'sqlite') {
            config(['database.connections.'.self::ALIAS_CONNECTION => config('database.connections.'.$this->primaryConnection)]);
            $this->connections[] = self::ALIAS_CONNECTION;
        }
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
            DB::table('pets')->insert(['id' => $id, 'owner_id' => $this->random->getInt(1, 4), 'name' => $this->name(), 'age' => $this->random->getInt(0, 9)]);
            DB::table('cars')->insert(['id' => $id, 'name' => $this->name(), 'driver_id' => $this->random->getInt(1, 4), 'engine_id' => null]);
        }
        foreach (range(1, 3) as $id) {
            DB::table('materials')->insert(['id' => $id, 'name' => $this->name()]);
            DB::table('car_material')->insert(['car_id' => $this->random->getInt(1, 5), 'material_id' => $id]);
            DB::table('pet_toys')->insert(['id' => $id, 'pet_id' => $this->random->getInt(1, 5), 'name' => $this->name()]);
            DB::table('owner_notes')->insert(['id' => $id, 'owner_id' => $this->random->getInt(1, 4), 'body' => $this->name()]);
        }
        DB::table('owner_audits')->insert(['id' => 1, 'owner_id' => 4, 'event' => 'signup']);
        foreach (array_slice(self::LICENSE_CODES, 0, 4) as $code) {
            DB::table('licenses')->insert(['code' => $code, 'holder' => $this->name()]);
        }

        if ($this->driver === 'pgsql') {
            foreach (['owners', 'drivers', 'pets', 'cars', 'pet_toys', 'owner_notes', 'owner_audits', 'materials'] as $table) {
                DB::statement("select setval(pg_get_serial_sequence('{$this->table($table)}', 'id'), 100)");
            }
        }
    }

    private static function licenseCode(int $id): string
    {
        $code = self::LICENSE_CODES[$id % count(self::LICENSE_CODES)];

        return $id % 3 === 0 ? strtoupper($code) : ($id % 3 === 1 ? strtolower($code) : $code);
    }

    private function table(string $name): string
    {
        return DB::connection()->getTablePrefix().$name;
    }

    private function toggleIndex(): void
    {
        if (DB::transactionLevel() > 0) {
            return;
        }

        $index = $this->table('fuzz_owner_name_index');
        $exists = DB::connection()->getSchemaBuilder()->hasIndex('owners', 'fuzz_owner_name_index');

        if (! $exists) {
            DB::statement("create index {$index} on {$this->table('owners')} (name)");

            return;
        }

        DB::statement($this->driver === 'mysql' ? "drop index {$index} on {$this->table('owners')}" : "drop index {$index}");
    }

    /**
     * Runs one random operation, comparing every read against the database.
     *
     * @param  bool  $compareReads  false while other processes write concurrently
     */
    public function step(bool $compareReads = true): void
    {
        $this->maybeSwitchConnection();
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

            if ($this->currentWrite !== null) {
                $this->writeStatistics[$this->currentWrite]['rejected']++;
                $this->currentWrite = null;
            }

            if (DB::transactionLevel() > 0) {
                DB::rollBack(0);
                $this->log('rolled back every open transaction');
            }
        }
    }

    /**
     * Seeds the data and runs the iterations, comparing every read shape for every id after
     * each $sweepEvery iterations and at the end.
     */
    public function run(int $iterations, int $sweepEvery = PHP_INT_MAX): void
    {
        $this->seedData();

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $this->step();

            if ($iteration % $sweepEvery === 0) {
                $this->finish();
                $this->sweep();
            }
        }

        $this->finish();
        $this->sweep();
    }

    public function finish(): void
    {
        foreach ($this->connections as $connection) {
            while (DB::connection($connection)->transactionLevel() > 0) {
                $this->log("commit on {$connection} at end of run");
                DB::connection($connection)->commit();
            }
        }

        DB::setDefaultConnection($this->primaryConnection);
    }

    /**
     * Every read shape as a query running through the cache, for checking properties that hold
     * for all of them.
     *
     * @return array<string, Closure(): mixed>
     */
    public function cachedReads(int $id): array
    {
        return array_map(
            static fn (Closure $shapeFor): Closure => static fn (): mixed => $shapeFor($id, self::NAMES[$id % 3])(true),
            $this->readShapes(),
        );
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

    /**
     * Write operations the database rejected every time, so the workload never exercised them.
     *
     * @return list<string>
     */
    public function writesAlwaysRejected(int $minimumRuns): array
    {
        $rejected = array_filter(
            $this->writeStatistics,
            static fn (array $statistics): bool => $statistics['runs'] >= $minimumRuns && $statistics['rejected'] === $statistics['runs'],
        );

        return array_keys($rejected);
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
            'pet by padded id' => fn (int $id) => $rows(fn () => DB::table('pets')->where('id', sprintf('%03d', $id))),
            'owners in mixed ids' => fn (int $id) => $rows(fn () => DB::table('owners')->whereIn('id', [(string) $id, $id + 1, sprintf('0%d', $id + 2)])->orderBy('id')),
            'pets in many ids' => fn (int $id) => $rows(fn () => DB::table('pets')->whereIn('id', range($id, $id + 150))->orderBy('id')),
            'license by code' => fn (int $id) => static fn (bool $cached): array => ($cached ? License::query() : License::query()->withoutCache())->whereKey(self::licenseCode($id))->get()->toArray(),
            'licenses by codes' => fn (int $id) => static fn (bool $cached): array => ($cached ? License::query() : License::query()->withoutCache())->whereKey([self::licenseCode($id), self::licenseCode($id + 1)])->orderBy('code')->get()->toArray(),
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
            'pet ages by owner' => fn () => $rows(fn () => DB::table('pets')->select('owner_id', DB::raw('sum(age) as total_age'), DB::raw('count(*) as pets'))->groupBy('owner_id')->havingRaw('count(*) > 0')->orderBy('owner_id')),
            'distinct pet names' => fn () => $scalar(fn () => DB::table('pets')->distinct()->orderBy('name'), static fn (Builder $query): array => $query->pluck('name')->all()),
            'oldest pet' => fn () => $rows(fn () => DB::table('pets')->orderByDesc('age')->orderBy('id')->limit(1)),
            'pets page' => fn (int $id) => $rows(fn () => DB::table('pets')->orderBy('id')->offset($id % 4)->limit(3)),
            'pet age value' => fn (int $id) => $scalar(fn () => DB::table('pets')->where('id', $id), static fn (Builder $query): mixed => $query->value('age')),
            'average age' => fn () => $scalar(fn () => DB::table('pets'), static fn (Builder $query): ?string => ($average = $query->avg('age')) === null ? null : number_format((float) $average, 4)),
            'no pets of owner' => fn (int $id) => $scalar(fn () => DB::table('pets')->where('owner_id', $id), static fn (Builder $query): bool => $query->doesntExist()),
            'pets where age column' => fn () => $rows(fn () => DB::table('pets')->whereColumn('age', '>', 'owner_id')->orderBy('id')->select('id')),
            'notes of owner names' => fn (int $id, string $name) => $rows(fn () => DB::table('owner_notes')->whereIn('owner_id', fn (Builder $query) => $query->select('id')->from('owners')->where('name', $name))->orderBy('id')),
            'car models with driver' => fn (int $id) => static fn (bool $cached): array => ($cached ? Car::query() : Car::query()->withoutCache())->with('driver:id,name')->whereKey([$id, $id + 1])->orderBy('id')->get(['id', 'name', 'driver_id'])->toArray(),
            'car models with materials' => fn (int $id) => static fn (bool $cached): array => ($cached ? Car::query() : Car::query()->withoutCache())->with('materials:id,name')->whereKey($id)->get(['id'])->map(static fn (Car $car): array => [$car->id, $car->materials->sortBy('id')->pluck('id')->values()->all()])->all(),
            'drivers with car count' => fn () => static fn (bool $cached): array => ($cached ? Driver::query() : Driver::query()->withoutCache())->withCount('cars')->orderBy('id')->get(['id'])->map(static fn (Driver $driver): array => [$driver->id, $driver->cars_count])->all(),
            'drivers having cars named' => fn (int $id, string $name) => static fn (bool $cached): array => ($cached ? Driver::query() : Driver::query()->withoutCache())->whereHas('cars', static fn (EloquentBuilder $query) => $query->where('name', $name))->orderBy('id')->pluck('id')->all(),
            'trashed cars' => fn () => static fn (bool $cached): array => ($cached ? Car::onlyTrashed() : Car::onlyTrashed()->withoutCache())->orderBy('id')->pluck('id')->all(),
            'owners with raw pet exists' => fn () => $rows(fn () => DB::table('owners')->whereRaw("exists (select 1 from {$this->table('pets')} where {$this->table('pets')}.owner_id = {$this->table('owners')}.id)")->orderBy('id')->select('id')),
            'raw pet count per owner' => fn (int $id) => $rows(fn () => DB::table('owners')->where('id', $id)->selectRaw("id, (select count(*) from {$this->table('pets')} where owner_id = {$this->table('owners')}.id) as pet_count")),
            'raw join toys' => fn () => $rows(fn () => DB::table('pets')->join(DB::raw($this->table('pet_toys')), 'pet_toys.pet_id', '=', 'pets.id')->orderBy('pet_toys.id')->select('pet_toys.id', 'pets.name')),
            'notes from raw' => fn () => $rows(fn () => DB::query()->fromRaw($this->table('owner_notes'))->orderBy('id')->select('id', 'owner_id')),
            'owner pet counts view' => fn () => $rows(fn () => DB::table('owner_pet_counts')->orderBy('owner_id')->select('owner_id', 'pet_count')),
            'pet count view of owner' => fn (int $id) => $rows(fn () => DB::table('owner_pet_counts')->where('owner_id', $id)->select('pet_count')),
            'car material pairs' => fn () => $rows(fn () => DB::table('car_material')->orderBy('car_id')->orderBy('material_id')->select('car_id', 'material_id')),
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
            'raw update pet' => fn () => DB::update("update {$this->table('pets')} set name = ? where id = ?", [$this->name(), $this->id()]),
            'raw insert note' => fn () => DB::insert("insert into {$this->table('owner_notes')} (owner_id, body) values (?, ?)", [$this->existingId('owners'), $this->name()]),
            'raw delete toy' => fn () => DB::delete("delete from {$this->table('pet_toys')} where id = ?", [$this->id()]),
            'update or insert owner' => fn () => DB::table('owners')->updateOrInsert(['id' => $this->id()], ['name' => $this->name()]),
            'create car model' => fn () => Car::query()->create(['name' => $this->name(), 'driver_id' => $this->existingId('drivers')]),
            'update car model' => fn () => Car::query()->withoutCache()->find($this->id())?->update(['name' => $this->name()]),
            'soft delete car model' => fn () => Car::query()->withoutCache()->find($this->id())?->delete(),
            'update driver' => fn () => DB::table('drivers')->where('id', $this->id())->update(['name' => $this->name()]),
            'truncate toys' => fn () => DB::transactionLevel() === 0 ? DB::table('pet_toys')->truncate() : null,
            'increment pet age' => fn () => DB::table('pets')->where('id', $this->id())->increment('age', $this->random->getInt(1, 3)),
            'decrement ages of owner' => fn () => DB::table('pets')->where('owner_id', $this->id())->decrement('age'),
            'increment many ages' => fn () => DB::table('pets')->whereIn('id', [$this->id(), $this->id()])->incrementEach(['age' => 1]),
            'update pets of named owners' => fn () => DB::table('pets')->whereIn('owner_id', DB::table('owners')->select('id')->where('name', $this->name()))->update(['name' => $this->name()]),
            'update pets joined to owners' => fn () => DB::table('pets')->join('owners', 'owners.id', '=', 'pets.owner_id')->where('owners.name', $this->name())->update(['pets.age' => $this->random->getInt(0, 9)]),
            'delete pets of named owners' => fn () => DB::table('pets')->whereExists(fn (Builder $query) => $query->select(DB::raw(1))->from('owners')->whereColumn('owners.id', 'pets.owner_id')->where('owners.name', $this->name()))->delete(),
            'copy owners into notes' => fn () => DB::table('owner_notes')->insertUsing(['owner_id', 'body'], DB::table('owners')->select('id', 'name')->where('name', $this->name())),
            'update without cache' => fn () => DB::table('owners')->withoutCache()->where('id', $this->id())->update(['name' => $this->name()]),
            'delete without cache' => fn () => DB::table('pet_toys')->withoutCache()->where('id', $this->id())->delete(),
            'raw multi statement' => fn () => DB::unprepared(sprintf("update %s set name = 'a' where id = %d; update %s set body = 'b' where id = %d", $this->table('owners'), $this->id(), $this->table('owner_notes'), $this->id())),
            'raw index ddl' => fn () => $this->toggleIndex(),
            'eloquent increment' => fn () => Car::query()->withoutCache()->find($this->id())?->increment('driver_id', 0, ['name' => $this->name()]),
            'eloquent restore' => fn () => Car::onlyTrashed()->withoutCache()->find($this->id())?->restore(),
            'eloquent force delete' => fn () => Car::withTrashed()->withoutCache()->find($this->id())?->forceDelete(),
            'sync car materials' => fn () => Car::query()->withoutCache()->find($this->id())?->materials()->sync(array_slice([1, 2, 3], 0, $this->random->getInt(0, 3))),
            'attach car material' => fn () => Car::query()->withoutCache()->find($this->id())?->materials()->syncWithoutDetaching([$this->random->getInt(1, 3)]),
            'update license' => fn () => License::query()->whereKey(self::licenseCode($this->id()))->update(['holder' => $this->name()]),
            'update pet by padded id' => fn () => DB::table('pets')->where('id', sprintf('%02d', $this->id()))->update(['name' => $this->name()]),
            'update pets in many ids' => fn () => DB::table('pets')->whereIn('id', range(1, 120))->update(['age' => $this->random->getInt(0, 9)]),
            'rekey license' => fn () => License::query()->whereKey(self::licenseCode($this->id()))->update(['code' => self::licenseCode($this->random->getInt(1, 2 * count(self::LICENSE_CODES)))]),
            'rename material' => fn () => DB::table('materials')->where('id', $this->random->getInt(1, 3))->update(['name' => $this->name()]),
            'eloquent mass update' => fn () => Car::query()->where('driver_id', $this->id())->update(['name' => $this->name()]),
            'eloquent mass delete' => fn () => Car::query()->whereKey($this->id())->delete(),
        ];

        $label = $this->pick(array_keys($writes));
        $this->log("write {$label}");
        $this->writeStatistics[$label] ??= ['runs' => 0, 'rejected' => 0];
        $this->writeStatistics[$label]['runs']++;
        $this->currentWrite = $label;
        $writes[$label]();
        $this->currentWrite = null;
    }

    /**
     * Moves the workload to another connection to the same database now and then, so writes
     * on one connection must invalidate reads cached through another. Only between
     * transactions, since a transaction on one connection would block writes on the other.
     */
    private function maybeSwitchConnection(): void
    {
        if (count($this->connections) < 2 || $this->random->getInt(1, 4) !== 1) {
            return;
        }

        foreach ($this->connections as $connection) {
            if (DB::connection($connection)->transactionLevel() > 0) {
                return;
            }
        }

        $connection = $this->pick($this->connections);
        $this->log("use connection {$connection}");
        DB::setDefaultConnection($connection);
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
