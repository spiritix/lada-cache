<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Fuzz;

use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;
use Spiritix\LadaCache\Tests\TestCase;

final class DifferentialFuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 400;

    private const int SWEEP_INTERVAL = 100;

    public function testCachedReadsAlwaysMatchTheDatabase(): void
    {
        $workload = new Workload(Workload::seedFromEnvironment(), self::databaseDriver());
        $workload->seedData();
        $iterations = Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS);

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $workload->step();

            if ($iteration % self::SWEEP_INTERVAL === 0) {
                $workload->finish();
                $workload->sweep();
            }
        }

        $workload->finish();
        $workload->sweep();

        $this->assertGreaterThan(0, $workload->comparisons());
    }

    public function testCachedReadsMatchTheDatabaseWithoutRowLevelTags(): void
    {
        config(['lada-cache.consider_rows' => false]);
        $workload = new Workload(Workload::seedFromEnvironment(), self::databaseDriver());
        $workload->seedData();
        $iterations = intdiv(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS), 2);

        for ($iteration = 1; $iteration <= $iterations; $iteration++) {
            $workload->step();
        }

        $workload->finish();
        $workload->sweep();

        $this->assertGreaterThan(0, $workload->comparisons());
    }
}
