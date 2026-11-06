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
        $this->runWorkload(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS));
    }

    public function testCachedReadsMatchTheDatabaseWithoutRowLevelTags(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $this->runWorkload(intdiv(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS), 2));
    }

    public function testCachedReadsMatchTheDatabaseWhenOnlyModelsAreCached(): void
    {
        config(['lada-cache.scope' => 'models']);

        $this->runWorkload(intdiv(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS), 2));
    }

    public function testCachedReadsMatchTheDatabaseWithoutExpiration(): void
    {
        config(['lada-cache.expiration_time' => 0]);
        $this->app->forgetInstance('lada.cache');
        $this->app->forgetInstance('lada.handler');

        $this->runWorkload(intdiv(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS), 2));
    }

    private function runWorkload(int $iterations): void
    {
        $workload = new Workload(Workload::seedFromEnvironment(), self::databaseDriver());
        $workload->run($iterations, self::SWEEP_INTERVAL);

        $this->assertGreaterThan(0, $workload->comparisons());
        $this->assertSame([], $workload->writesAlwaysRejected(5), 'These writes never succeeded, so the workload does not exercise them');
    }
}
