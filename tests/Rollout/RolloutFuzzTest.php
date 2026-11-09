<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Rollout;

use PHPUnit\Framework\TestCase;

/**
 * Long-lived hosts of both versions, like Octane workers of the old and the new release, run
 * seeded random reads and writes against the same rows at the same time. Races may make reads
 * differ while they run, but once every host has stopped writing, each host's own cached reads
 * must match the database. A failure names the seed; rerun with LADA_FUZZ_SEED=<seed>.
 */
final class RolloutFuzzTest extends TestCase
{
    private const int WORKERS_PER_VERSION = 3;

    private const int ROUNDS = 3;

    private const int DEFAULT_STEPS_PER_WORKER = 150;

    public function testHostsOfBothVersionsAgreeWithTheDatabaseAfterConcurrentWorkloads(): void
    {
        $reason = Pods::skipReason();

        if ($reason !== null) {
            $this->markTestSkipped($reason);
        }

        $seed = (int) (getenv('LADA_FUZZ_SEED') ?: random_int(1, 1_000_000));
        $steps = (int) (getenv('LADA_FUZZ_ITERATIONS') ?: self::DEFAULT_STEPS_PER_WORKER);
        $pods = new Pods;
        $pods->run('new', 'reset');

        for ($round = 1; $round <= self::ROUNDS; $round++) {
            $barrier = sys_get_temp_dir().'/lada-rollout-'.bin2hex(random_bytes(6));
            mkdir($barrier);
            $commands = [];

            foreach (['old', 'new'] as $version) {
                for ($worker = 0; $worker < self::WORKERS_PER_VERSION; $worker++) {
                    $workerSeed = $seed + $round * 100 + count($commands);
                    $commands[] = [$version, 'worker', [$workerSeed, $steps, $barrier, self::WORKERS_PER_VERSION * 2]];
                }
            }

            $mismatches = array_merge(...$pods->runConcurrently($commands));

            foreach ((array) glob("{$barrier}/*") as $marker) {
                unlink((string) $marker);
            }
            rmdir($barrier);

            $this->assertSame([], $mismatches, "Round {$round} (LADA_FUZZ_SEED={$seed})");
        }
    }
}
