<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Calibration;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spiritix\LadaCache\Calibration\TtlCalibrationRepository;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\TtlResolver;

class TtlCalibrationRepositoryTest extends TestCase
{
    private string $sandboxTable = 'lada_cache_calibrations_test';

    protected function setUp(): void
    {
        parent::setUp();

        // Sandbox calibration table so we never touch shared rows and the suite
        // stays hermetic when run repeatedly.
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

        Cache::forget(TtlCalibrationRepository::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists($this->sandboxTable);
        Cache::forget(TtlCalibrationRepository::CACHE_KEY);

        parent::tearDown();
    }

    public function testFindForModelReturnsNullWhenNoRowExists(): void
    {
        $repo = new TtlCalibrationRepository($this->sandboxTable);

        $this->assertNull($repo->findForModel(Car::class));
    }

    public function testUpsertThenFindReturnsCalibratedTtl(): void
    {
        $repo = new TtlCalibrationRepository($this->sandboxTable);

        $repo->upsert(Car::class, 'cars', 600, ['samples' => 100, 'p50' => 120, 'p95' => 300, 'max' => 500]);

        $this->assertSame(600, $repo->findForModel(Car::class));

        $row = DB::table($this->sandboxTable)->where('model_class', Car::class)->first();
        $this->assertNotNull($row);
        $this->assertSame('cars', $row->table_name);
        $this->assertSame(600, (int) $row->calibrated_ttl);

        $metrics = json_decode($row->metrics, true);
        $this->assertSame(100, $metrics['samples']);
        $this->assertSame(300, $metrics['p95']);
    }

    public function testUpsertIsIdempotentAndOverwrites(): void
    {
        $repo = new TtlCalibrationRepository($this->sandboxTable);

        $repo->upsert(Car::class, 'cars', 600, ['samples' => 100]);
        $repo->upsert(Car::class, 'cars', 1200, ['samples' => 200]);

        $this->assertSame(1200, $repo->findForModel(Car::class));
        $this->assertSame(1, DB::table($this->sandboxTable)->where('model_class', Car::class)->count());
    }

    public function testMapIsCachedUntilBust(): void
    {
        Config::set('lada-cache.calibration.cache_ttl', 60);
        $repo = new TtlCalibrationRepository($this->sandboxTable);

        $repo->upsert(Car::class, 'cars', 600, ['samples' => 100]);
        $this->assertSame(600, $repo->findForModel(Car::class));

        // Mutate the DB directly — without bust(), repo should still return cached value.
        DB::table($this->sandboxTable)
            ->where('model_class', Car::class)
            ->update(['calibrated_ttl' => 9999])
        ;

        $this->assertSame(600, $repo->findForModel(Car::class));

        $repo->bust();
        $this->assertSame(9999, $repo->findForModel(Car::class));
    }

    public function testResolverPrefersCalibratedOverConfigWhenEnabled(): void
    {
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.model_ttls', [Car::class => 7200]);

        $repo = new TtlCalibrationRepository($this->sandboxTable);
        $repo->upsert(Car::class, 'cars', 300, ['samples' => 100]);

        $resolver = new TtlResolver($repo);

        $this->assertSame(300, $resolver->resolve(new Car));
    }

    public function testResolverSkipsCalibrationWhenFlagDisabled(): void
    {
        Config::set('lada-cache.calibration.enabled', false);
        Config::set('lada-cache.model_ttls', [Car::class => 7200]);

        $repo = new TtlCalibrationRepository($this->sandboxTable);
        $repo->upsert(Car::class, 'cars', 300, ['samples' => 100]);

        $resolver = new TtlResolver($repo);

        // Calibration ignored → falls through to model_ttls config
        $this->assertSame(7200, $resolver->resolve(new Car));
    }

    public function testResolverSwallowsDbErrorsDuringCalibrationLookup(): void
    {
        Config::set('lada-cache.calibration.enabled', true);
        Config::set('lada-cache.model_ttls', [Car::class => 7200]);
        Cache::forget(TtlCalibrationRepository::CACHE_KEY);

        // Point repository at a non-existent table → DB error on read.
        $repo = new TtlCalibrationRepository('lada_cache_calibrations_missing_table');
        $resolver = new TtlResolver($repo);

        // Should fall through to config rather than throwing.
        $this->assertSame(7200, $resolver->resolve(new Car));
    }
}
