<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Console\CalibrateCommand;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class CalibrateCommandTest extends TestCase
{
    private string $sandboxTable = 'lada_cache_calibrations_cmd_test';

    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.enable_debugbar' => false]);

        Schema::dropIfExists($this->sandboxTable);
        Schema::create($this->sandboxTable, function (Blueprint $t): void {
            $t->id();
            $t->string('model_class')->unique();
            $t->string('table_name')->index();
            $t->unsignedInteger('calibrated_ttl');
            $t->json('metrics');
            $t->timestamp('calibrated_at');
            $t->timestamps();
        });

        Config::set('lada-cache.active', true);
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.calibration.min_samples', 1);
        Config::set('lada-cache.calibration.safety_factor', 2.0);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        parent::tearDown();
    }

    public function testCommandExitsCleanlyWhenLadaDisabled(): void
    {
        Config::set('lada-cache.active', false);

        $this->assertSame(Command::SUCCESS, $this->runCalibrateCommand([]));
    }

    public function testCommandConstructibleWithoutDependenciesWhenDisabled(): void
    {
        // Service provider instantiates `new CalibrateCommand()` (no deps) when Lada
        // is disabled; the graceful SUCCESS path must work without redis/repo bound.
        Config::set('lada-cache.active', false);

        $command = new CalibrateCommand;
        $command->setLaravel($this->app);

        $exit = $command->run(new ArrayInput([]), new BufferedOutput);

        $this->assertSame(Command::SUCCESS, $exit);
    }

    public function testCommandFailsLoudlyWhenActiveButDepsMissing(): void
    {
        // Defensive guard: misconfig (active=true but deps null) must FAIL with a
        // descriptive message rather than silently succeed.
        Config::set('lada-cache.active', true);

        $command = new CalibrateCommand;
        $command->setLaravel($this->app);

        $exit = $command->run(new ArrayInput([]), new BufferedOutput);

        $this->assertSame(Command::FAILURE, $exit);
    }

    public function testCommandExitsCleanlyWhenCalibrationDisabled(): void
    {
        Config::set('lada-cache.calibration.enabled', false);

        $this->assertSame(Command::SUCCESS, $this->runCalibrateCommand([]));
    }

    public function testCommandRejectsInvalidModelArgument(): void
    {
        // discover() returns []; command warns and exits success (not a fatal error).
        $exit = $this->runCalibrateCommand(['--model' => '\\Not\\A\\Real\\Class']);

        $this->assertSame(Command::SUCCESS, $exit);
    }

    public function testDryRunReportsMetricsWithoutPersisting(): void
    {
        $this->seedCacheEntriesForCars(samples: 3);

        $exit = $this->runCalibrateCommand(['--model' => Car::class]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(0, DB::table($this->sandboxTable)->count(), 'Dry-run must not persist rows.');
    }

    public function testApplyPersistsCalibratedTtlRow(): void
    {
        $this->seedCacheEntriesForCars(samples: 3);

        $exit = $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exit);

        $row = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();
        $this->assertNotNull($row, '--apply must persist a calibration row.');
        $this->assertSame('cars', $row->table_name);
        // Freshly seeded keys can have OBJECT IDLETIME = 0 → calibrated_ttl 0 is valid; assert >= 0.
        $this->assertGreaterThanOrEqual(0, (int) $row->calibrated_ttl);

        $metrics = json_decode($row->metrics, true);
        $this->assertGreaterThanOrEqual(3, $metrics['samples']);
        $this->assertArrayHasKey('p95', $metrics);
    }

    public function testSkipsModelWhenSamplesBelowMinThreshold(): void
    {
        Config::set('lada-cache.calibration.min_samples', 1000);
        $this->seedCacheEntriesForCars(samples: 3);

        $exit = $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(0, DB::table($this->sandboxTable)->count(), 'Below-threshold models must not be persisted.');
    }

    public function testRejectsNonPositiveSafetyFactor(): void
    {
        $this->seedCacheEntriesForCars(samples: 3);

        $exitZero = $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true, '--safety-factor' => '0']);
        $this->assertSame(Command::INVALID, $exitZero, 'safety-factor=0 must abort with INVALID.');

        $exitNeg = $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true, '--safety-factor' => '-1']);
        $this->assertSame(Command::INVALID, $exitNeg, 'safety-factor<0 must abort with INVALID.');

        $this->assertSame(0, DB::table($this->sandboxTable)->count(), 'Invalid safety-factor must not persist rows.');
    }

    public function testSkipsModelWithZeroSamplesEvenWhenMinThresholdZero(): void
    {
        Config::set('lada-cache.calibration.min_samples', 0);
        // No seeding → samples will be 0.

        $exit = $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertSame(0, DB::table($this->sandboxTable)->count(), 'Zero-sample model must never be persisted regardless of min_samples.');
    }

    public function testApplyRespectsFloorAgainstSurvivorBias(): void
    {
        // Establish a current TTL via config so the floor can be computed.
        Config::set('lada-cache.model_ttls', [Car::class => 1000]);
        $this->seedCacheEntriesForCars(samples: 3);
        // Freshly seeded → P95 ≈ 0, so raw_calibrated ≈ 0. Floor should pull it to 500.

        $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true]);

        $row = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();
        $this->assertNotNull($row);
        $this->assertSame(500, (int) $row->calibrated_ttl, 'Floor should clamp to previous_ttl / 2 = 500.');

        $metrics = json_decode($row->metrics, true);
        $this->assertSame(1000, $metrics['previous_ttl']);
        $this->assertSame(500, $metrics['floor']);
        $this->assertArrayHasKey('raw_calibrated', $metrics);
        $this->assertArrayHasKey('safety_factor', $metrics);
    }

    public function testFloorDoesNotClampWhenRawIsHigher(): void
    {
        Config::set('lada-cache.model_ttls', [Car::class => 10]);
        $this->seedCacheEntriesForCars(samples: 3);
        sleep(2);
        // P95 IDLETIME ≈ 2, raw_calibrated = 2 × 10 = 20; floor = 10/2 = 5; max(20, 5) = 20

        $this->runCalibrateCommand([
            '--model' => Car::class,
            '--apply' => true,
            '--safety-factor' => '10',
        ]);

        $row = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();
        $this->assertNotNull($row);
        $metrics = json_decode($row->metrics, true);
        $this->assertSame(5, $metrics['floor']);
        $this->assertGreaterThanOrEqual($metrics['raw_calibrated'], (int) $row->calibrated_ttl);
        $this->assertGreaterThan($metrics['floor'], (int) $row->calibrated_ttl, 'raw_calibrated > floor → floor must not clamp.');
    }

    public function testApplyUsesSafetyFactorOverride(): void
    {
        $this->seedCacheEntriesForCars(samples: 3);
        // Ensure OBJECT IDLETIME advances ≥1s so factor differences materialize.
        sleep(1);

        $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true, '--safety-factor' => '1']);
        $rowOne = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();

        DB::table($this->sandboxTable)->truncate();

        $this->runCalibrateCommand(['--model' => Car::class, '--apply' => true, '--safety-factor' => '10']);
        $rowTen = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();

        $this->assertNotNull($rowOne);
        $this->assertNotNull($rowTen);
        // 10x factor must produce at least as large a TTL; with P95≥1 the inequality is strict.
        $this->assertGreaterThanOrEqual((int) $rowOne->calibrated_ttl, (int) $rowTen->calibrated_ttl);
    }

    public function testFullDiscoveryPicksUpModelsUsingLadaCacheTrait(): void
    {
        // No --model filter → discoverModels() walks the configured namespace. Car uses
        // LadaCacheTrait — smoke test that the discovery path actually finds it
        // end-to-end (file scan → reflection → table resolve → upsert).
        $this->seedCacheEntriesForCars(samples: 3);

        $exit = $this->runCalibrateCommand([
            '--apply' => true,
            '--models-path' => realpath(__DIR__.'/../Database/Models'),
            '--models-namespace' => 'Spiritix\\LadaCache\\Tests\\Database\\Models',
        ]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertNotNull(
            DB::table($this->sandboxTable)->where('model_class', Car::class)->first(),
            'Full-scan discovery must include Car (LadaCacheTrait).',
        );
    }

    public function testFullDiscoveryScansTheKeyspaceOnceAndGroupsTagsByTable(): void
    {
        // SCAN MATCH walks every key in the database whatever the pattern, so one
        // round costs O(total keys). Scanning twice per model walked the whole
        // keyspace 2 × models times per run; a single round must feed every model.
        $scanPrefix = (string) config('lada-cache.prefix', 'lada:').'tags:database:';

        $client = new class {
            public int $scans = 0;

            /** @var list<string> */
            public array $tagKeys = [];

            /**
             * @param  array<string, mixed>  $options
             * @return array{0: string, 1: list<string>}
             */
            public function scan(string $cursor, array $options): array
            {
                $this->scans++;

                return ['0', $this->tagKeys];
            }

            /**
             * @param  array<string, mixed>  $options
             * @return array{0: string, 1: list<string>}
             */
            public function sscan(string $key, string $cursor, array $options): array
            {
                return ['0', ['member-of-'.$key]];
            }

            public function object(string $subcommand, string $key): int
            {
                return 60;
            }
        };

        $client->tagKeys = [
            $scanPrefix.'test:table_specific:cars',
            $scanPrefix.'test:table_unspecific:cars',
            // SCAN may return the same key more than once.
            $scanPrefix.'test:table_specific:cars',
            // Row-scoped tag: must not count as a table tag.
            $scanPrefix.'test:table_specific:cars:row:5',
            // Table whose name merely ends with a model's table name.
            $scanPrefix.'test:table_specific:used_cars',
            $scanPrefix.'test:table_specific:car_material',
            $scanPrefix.'test:table_specific:drivers',
        ];

        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('client')->andReturn($client);

        $command = new CalibrateCommand(
            new Redis($connection),
            new TtlCalibrationRepository($this->sandboxTable),
        );
        $command->setLaravel($this->app);

        $options = [
            '--apply' => true,
            '--models-path' => realpath(__DIR__.'/../Database/Models'),
            '--models-namespace' => 'Spiritix\\LadaCache\\Tests\\Database\\Models',
        ];

        $this->assertSame(Command::SUCCESS, $command->run(new ArrayInput($options), new BufferedOutput));
        $this->assertSame(1, $client->scans, 'The keyspace must be scanned once per run, not once per model.');

        $samples = DB::table($this->sandboxTable)
            ->get(['table_name', 'metrics'])
            ->mapWithKeys(static fn (object $row): array => [
                $row->table_name => json_decode($row->metrics, true)['samples'],
            ])
            ->all();

        $this->assertSame(2, $samples['cars'] ?? null, 'cars must count its two table tags only.');
        $this->assertSame(1, $samples['car_material'] ?? null);
        $this->assertSame(1, $samples['drivers'] ?? null);
        $this->assertArrayNotHasKey('materials', $samples, 'Models without tags must stay unsampled.');

        // Artisan reuses command instances in long-running workers; a second run
        // must rescan rather than reuse the previous run's index.
        $command->run(new ArrayInput($options), new BufferedOutput);
        $this->assertSame(2, $client->scans, 'Each run must rescan the keyspace.');
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    /**
     * Run the calibrate command against the sandboxed calibrations table.
     * Bypasses the container singleton (which points at the real table) by invoking
     * the command directly with explicit input/output.
     *
     * @param array<string, scalar|bool> $options
     */
    private function runCalibrateCommand(array $options): int
    {
        $command = new CalibrateCommand(
            app('lada.redis'),
            new TtlCalibrationRepository($this->sandboxTable),
        );
        $command->setLaravel($this->app);

        return $command->run(new ArrayInput($options), new BufferedOutput);
    }

    /**
     * Seed N tagged cache entries for the `cars` table so the command has something to measure.
     */
    private function seedCacheEntriesForCars(int $samples): void
    {
        /** @var \Spiritix\LadaCache\Cache $cache */
        $cache = app('lada.cache');
        $database = (string) config('database.connections.'.config('database.default').'.database');
        $tag = sprintf('tags:database:%s:table_specific:cars', $database);

        for ($i = 0; $i < $samples; $i++) {
            $cache->set(sprintf('test-calibrate-car-%d', $i), [$tag], ['v' => $i], 3600);
        }
    }
}
