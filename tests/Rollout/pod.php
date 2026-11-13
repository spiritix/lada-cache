<?php

declare(strict_types=1);

/*
 * One host of a rolling deploy: boots an application on either this version ("new") or the
 * 6.x release in tests/Rollout/legacy ("old"), against the database and Redis named by the
 * environment, runs one operation and prints its result as JSON. RolloutTest starts these as
 * separate processes, so hosts of both versions share the database and Redis like pods do.
 *
 * Usage: php pod.php old|new <operation> [arguments...]
 */

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\Foundation\Application;
use Spiritix\LadaCache\Database\LadaCacheTrait;
use Spiritix\LadaCache\LadaCacheServiceProvider;

$version = $argv[1] ?? '';
$operation = $argv[2] ?? '';
$arguments = array_slice($argv, 3);

$autoloaders = [
    'old' => __DIR__.'/legacy/vendor/autoload.php',
    'new' => dirname(__DIR__, 2).'/vendor/autoload.php',
];

if (! isset($autoloaders[$version])) {
    fwrite(STDERR, "usage: pod.php old|new <operation> [arguments...]\n");
    exit(2);
}

$redisDatabase = (string) (getenv('ROLLOUT_REDIS_DB') ?: '7');

foreach ([
    'REDIS_DB' => $redisDatabase,
    'REDIS_CACHE_DB' => $redisDatabase,
    'REDIS_PREFIX' => (string) getenv('ROLLOUT_REDIS_PREFIX'),
    'LADA_CACHE_ACTIVE' => 'true',
    'LADA_CACHE_REDIS_CONNECTION' => 'default',
    'LADA_CACHE_LEGACY_INVALIDATION' => getenv('ROLLOUT_LEGACY') === '0' ? 'false' : 'true',
    'LADA_CACHE_SCOPE' => 'models',
    'LADA_CACHE_EXPIRATION_JITTER' => '0',
    'LADA_CACHE_ENABLE_DEBUGBAR' => 'false',
] as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require $autoloaders[$version];

$app = Application::create(options: ['extra' => ['providers' => [LadaCacheServiceProvider::class]]]);

if ($version === 'old') {
    $app['config']->set('lada-cache.prefix', 'lada:');
    $app['config']->set('lada-cache.expiration-time', 3600);
    $app['config']->set('lada-cache.consider-rows', true);
}

final class RolloutCar extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $table = 'rollout_cars';

    protected $guarded = [];
}

final class RolloutDriver extends Model
{
    use LadaCacheTrait;

    public $timestamps = false;

    protected $table = 'rollout_drivers';

    protected $guarded = [];
}

/**
 * Reads and writes a pod issues, by name. Reads go through the cache; `db-*` reads bypass
 * Laravel's builders, so they show what the database holds.
 */
function operation(string $version, string $name, array $arguments): mixed
{
    $id = (int) ($arguments[0] ?? 0);
    $value = (string) ($arguments[1] ?? '');

    return match ($name) {
        'reset' => reset_schema(),
        'find' => normalized_row(RolloutCar::query()->find($id)?->getAttributes()),
        'all' => RolloutCar::query()->orderBy('id')->pluck('name')->all(),
        'count' => RolloutCar::query()->count(),
        'where-name' => RolloutCar::query()->where('name', $arguments[0] ?? '')->orderBy('id')->pluck('id')->all(),
        'where-in' => RolloutCar::query()->whereIn('id', array_map('intval', $arguments))->orderBy('id')->pluck('name')->all(),
        'drivers-of' => RolloutDriver::query()->where('car_id', $id)->orderBy('id')->pluck('name')->all(),
        'update' => RolloutCar::query()->whereKey($id)->update(['name' => $value]),
        'model-save' => RolloutCar::query()->findOrFail($id)->forceFill(['name' => $value])->save(),
        'insert' => RolloutCar::query()->insertGetId(['name' => (string) ($arguments[0] ?? '')]),
        'insert-with-id' => RolloutCar::query()->insert(['id' => $id, 'name' => $value]),
        'delete' => RolloutCar::query()->whereKey($id)->delete(),
        'db-update' => DB::table('rollout_cars')->where('id', $id)->update(['name' => $value]),
        'raw-update' => DB::update('update rollout_cars set name = ? where id = ?', [$value, $id]),
        'tx-commit' => DB::transaction(static fn () => RolloutCar::query()->whereKey($id)->update(['name' => $value])),
        'tx-rollback' => tx_rollback($id, $value),
        'migrate-add-color' => migrate_add_color(),
        'migrate-failing' => migrate_failing(),
        'flush' => Artisan::call('lada-cache:flush'),
        'redis-emptied' => Redis::connection('default')->flushdb() !== false,
        'db-find' => db_find($id),
        'db-all' => array_column(DB::connection()->getPdo()->query('select name from rollout_cars order by id')->fetchAll(PDO::FETCH_ASSOC), 'name'),
        'db-count' => (int) DB::connection()->getPdo()->query('select count(*) from rollout_cars')->fetchColumn(),
        'legacy-keys' => count(legacy_keys()),
        'worker' => worker($version, (int) $arguments[0], (int) $arguments[1], (string) $arguments[2], (int) $arguments[3]),
        default => throw new InvalidArgumentException("Unknown operation {$name}"),
    };
}

function reset_schema(): bool
{
    Schema::dropIfExists('rollout_drivers');
    Schema::dropIfExists('rollout_cars');
    Schema::create('rollout_cars', static function (Blueprint $table): void {
        $table->id();
        $table->string('name');
    });
    Schema::create('rollout_drivers', static function (Blueprint $table): void {
        $table->id();
        $table->foreignId('car_id')->nullable()->constrained('rollout_cars')->cascadeOnDelete();
        $table->string('name');
    });
    Redis::connection('default')->flushdb();
    $pdo = DB::connection()->getPdo();
    $pdo->exec("insert into rollout_cars (id, name) values (1, 'a1'), (2, 'b1'), (3, 'c1'), (4, 'd1')");
    $pdo->exec("insert into rollout_drivers (id, car_id, name) values (1, 1, 'driver1')");

    if (DB::getDriverName() === 'pgsql') {
        $pdo->exec("select setval('rollout_cars_id_seq', 4)");
        $pdo->exec("select setval('rollout_drivers_id_seq', 1)");
    }

    return true;
}

function tx_rollback(int $id, string $value): bool
{
    try {
        DB::transaction(static function () use ($id, $value): never {
            RolloutCar::query()->whereKey($id)->update(['name' => $value]);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        return true;
    }

    return false;
}

function migrate_add_color(): bool
{
    Schema::table('rollout_cars', static function (Blueprint $table): void {
        $table->string('color')->default('red');
    });
    event(new MigrationsEnded('up'));

    return true;
}

function migrate_failing(): bool
{
    Schema::table('rollout_cars', static function (Blueprint $table): void {
        $table->string('color')->default('red');
    });

    return false;
}

function db_find(int $id): ?array
{
    $statement = DB::connection()->getPdo()->prepare('select * from rollout_cars where id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);

    return normalized_row($row === false ? null : $row);
}

/**
 * Drivers return integer columns as integers or strings depending on how a row was read.
 */
function normalized_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }

    return array_map(static fn (mixed $value): mixed => is_string($value) && preg_match('/^-?[1-9][0-9]*$|^0$/', $value) === 1 ? (int) $value : $value, $row);
}

/**
 * @return list<string>
 */
function legacy_keys(): array
{
    $connection = Redis::connection('default');
    $prefix = (string) getenv('ROLLOUT_REDIS_PREFIX');
    $keys = [];

    foreach ((array) $connection->keys('lada:*') as $key) {
        $unprefixed = substr((string) $key, strlen($prefix));

        if (preg_match('/^lada:(tags:|[0-9a-f]{32}$)/', $unprefixed) === 1) {
            $keys[] = $unprefixed;
        }
    }

    return $keys;
}

/**
 * A long-lived host, like an Octane worker: runs a seeded random workload, waits until every
 * worker of the run has finished writing, then checks that each of its own cached reads matches
 * the database. Returns the mismatches it found.
 *
 * @return list<string>
 */
/**
 * Marks this worker as having reached the phase and waits for every worker to reach it.
 *
 * @return string|null the phase timed out on, null when every worker reached it
 */
function wait_at_barrier(string $barrierDirectory, string $phase, string $worker, int $workers): ?string
{
    touch("{$barrierDirectory}/{$phase}-{$worker}");
    $deadline = microtime(true) + 120;

    while (count((array) glob("{$barrierDirectory}/{$phase}-*")) < $workers) {
        if (microtime(true) > $deadline) {
            return "reach {$phase}";
        }

        usleep(50_000);
    }

    return null;
}

function worker(string $version, int $seed, int $steps, string $barrierDirectory, int $workersPerVersion): array
{
    mt_srand($seed);
    $ids = [1, 2, 3, 4];
    $writes = $version === 'new'
        ? ['update', 'model-save', 'db-update', 'raw-update', 'tx-commit', 'tx-rollback']
        : ['update', 'model-save', 'tx-commit', 'tx-rollback'];
    $reads = ['find', 'all', 'count', 'where-in', 'where-name'];

    for ($step = 0; $step < $steps; $step++) {
        $id = $ids[mt_rand(0, count($ids) - 1)];

        if (mt_rand(0, 2) === 0) {
            $write = $writes[mt_rand(0, count($writes) - 1)];
            operation($version, $write, [$id, "{$version}-{$seed}-{$step}"]);

            continue;
        }

        $read = $reads[mt_rand(0, count($reads) - 1)];
        operation($version, $read, match ($read) {
            'where-in' => [$id, $ids[mt_rand(0, count($ids) - 1)]],
            'where-name' => ["{$version}-{$seed}-".mt_rand(0, max(0, $step - 1))],
            default => [$id],
        });
    }

    $timedOut = wait_at_barrier($barrierDirectory, 'done', "{$version}-{$seed}", $workersPerVersion * 2);

    if ($timedOut === null && $version === 'new') {
        foreach ($ids as $id) {
            operation($version, 'update', [$id, "{$version}-{$seed}-final-{$id}"]);
        }
    }

    $timedOut ??= wait_at_barrier($barrierDirectory, 'final', "{$version}-{$seed}", $workersPerVersion * 2);

    if ($timedOut !== null) {
        return ["{$version} worker {$seed} timed out waiting for the other workers to {$timedOut}"];
    }

    $mismatches = [];
    $expectedAll = operation($version, 'db-all', []);

    foreach ([
        'all' => [[], $expectedAll],
        'count' => [[], count($expectedAll)],
        'where-in' => [[1, 3], [$expectedAll[0], $expectedAll[2]]],
    ] as $read => [$readArguments, $expected]) {
        $actual = operation($version, $read, $readArguments);

        if ($actual !== $expected) {
            $mismatches[] = "{$version} worker {$seed} {$read}: cached ".json_encode($actual).', database '.json_encode($expected);
        }
    }

    foreach ($ids as $id) {
        $expected = operation($version, 'db-find', [$id]);
        $actual = operation($version, 'find', [$id]);

        if ($actual !== $expected) {
            $mismatches[] = "{$version} worker {$seed} find {$id}: cached ".json_encode($actual).', database '.json_encode($expected);
        }

        $name = $expected['name'];
        $actualIds = operation($version, 'where-name', [$name]);

        if ($actualIds !== [$id]) {
            $mismatches[] = "{$version} worker {$seed} where-name {$name}: cached ".json_encode($actualIds).", database [{$id}]";
        }
    }

    return $mismatches;
}

echo json_encode(operation($version, $operation, $arguments)), PHP_EOL;
