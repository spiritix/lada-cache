<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis as RedisFacade;
use Spiritix\LadaCache\Tests\Fuzz\Support\Workload;
use Spiritix\LadaCache\Tests\TestCase;
use Throwable;

/**
 * Several processes, like pods sharing one database and one Redis, run random workloads at
 * the same time. Races may make reads differ while they run, but once every process has
 * finished, every cached read must match the database again.
 */
final class MultiProcessTest extends TestCase
{
    private const int WORKERS = 4;

    private const int ROUNDS = 3;

    private const int DEFAULT_STEPS_PER_WORKER = 150;

    public function testCacheIsConsistentAfterConcurrentWorkloads(): void
    {
        $this->requireDatabaseDriver('mysql', 'pgsql');

        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Needs the pcntl extension to run concurrent workers.');
        }

        $seed = Workload::seedFromEnvironment();
        $steps = Workload::iterationsFromEnvironment(self::DEFAULT_STEPS_PER_WORKER);
        $verifier = new Workload($seed, self::databaseDriver());
        $verifier->seedData();

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $failures = $this->runWorkers($seed + $round * self::WORKERS, $steps);

            $this->assertSame([], $failures, "Workers failed in round {$round} (LADA_FUZZ_SEED={$seed})");

            $verifier->sweep();
        }

        $this->assertGreaterThan(0, $verifier->comparisons());
    }

    /**
     * @return list<string>
     */
    private function runWorkers(int $firstSeed, int $steps): array
    {
        $this->releaseConnections();
        $reports = [];
        $children = [];

        for ($worker = 0; $worker < self::WORKERS; $worker++) {
            $report = (string) tempnam(sys_get_temp_dir(), 'lada-worker-');
            $reports[] = $report;
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Could not fork a worker process');
            }

            if ($pid === 0) {
                $this->runWorker($firstSeed + $worker, $steps, $report);
            }

            $children[] = $pid;
        }

        foreach ($children as $child) {
            pcntl_waitpid($child, $status);
        }

        $failures = [];
        foreach ($reports as $report) {
            $outcome = (string) file_get_contents($report);
            unlink($report);

            if ($outcome !== 'ok') {
                $failures[] = $outcome === '' ? 'worker exited without reporting' : $outcome;
            }
        }

        return $failures;
    }

    private function runWorker(int $seed, int $steps, string $report): never
    {
        try {
            $workload = new Workload($seed, self::databaseDriver());

            for ($step = 0; $step < $steps; $step++) {
                $workload->step(compareReads: false);
            }

            $workload->finish();
            file_put_contents($report, 'ok');
        } catch (Throwable $exception) {
            file_put_contents($report, sprintf("worker seed %d: %s: %s\n%s", $seed, $exception::class, $exception->getMessage(), $exception->getTraceAsString()));
        }

        DB::disconnect();
        posix_kill(posix_getpid(), SIGKILL);

        exit(1);
    }

    private function releaseConnections(): void
    {
        DB::disconnect();
        RedisFacade::purge((string) config('lada-cache.redis_connection'));

        foreach (['lada.redis', 'lada.cache', 'lada.handler'] as $binding) {
            $this->app->forgetInstance($binding);
        }
    }
}
