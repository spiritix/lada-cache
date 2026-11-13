<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Fuzz;

use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;
use Spiritix\LadaCache\Tests\TestCase;

final class PrefixedTablesFuzzTest extends TestCase
{
    private const int DEFAULT_ITERATIONS = 200;

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $connection = (string) $app['config']->get('database.default');
        $app['config']->set("database.connections.{$connection}.prefix", 'lc_');
        $app['config']->set("database.connections.{$connection}.prefix_indexes", true);
    }

    public function testCachedReadsMatchTheDatabaseWithPrefixedTables(): void
    {
        $workload = new Workload(Workload::seedFromEnvironment(), self::databaseDriver());
        $workload->run(Workload::iterationsFromEnvironment(self::DEFAULT_ITERATIONS));

        $this->assertGreaterThan(0, $workload->comparisons());
    }
}
