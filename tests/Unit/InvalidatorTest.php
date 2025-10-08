<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Redis\Connections\PredisConnection as RedisConnection;
use PHPUnit\Framework\MockObject\MockObject;
use Spiritix\LadaCache\Invalidator;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;
use Throwable;

class InvalidatorTest extends TestCase
{
    /** @var RedisConnection&MockObject */
    private RedisConnection $connection;
    private Redis $redis;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lada-cache.prefix' => 'p:']);

        $this->connection = $this->getMockBuilder(RedisConnection::class)
            ->disableOriginalConstructor()
            ->addMethods(['exists', 'multi', 'smembers', 'del', 'exec', 'unlink'])
            ->getMock();

        $this->redis = new Redis($this->connection);
    }

    public function testInvalidateReturnsEmptyWhenTagsDoNotExist(): void
    {
        $tags = ['tag:a', 'tag:b'];
        $tagKeyA = 'p:tag:a';
        $tagKeyB = 'p:tag:b';

        // exists() returns 0 for each tag key
        $this->connection->expects($this->exactly(2))
            ->method('exists')
            ->willReturnMap([
                [$tagKeyA, 0],
                [$tagKeyB, 0],
            ]);

        // No multi/exec/del/unlink should be called
        $this->connection->expects($this->never())->method('multi');
        $this->connection->expects($this->never())->method('exec');
        $this->connection->expects($this->never())->method('del');
        $this->connection->expects($this->never())->method('unlink');

        $invalidator = new Invalidator($this->redis);
        $result = $invalidator->invalidate($tags);

        $this->assertSame([], $result);
    }

    public function testInvalidateCollectsMembersDeletesTagSetsAndUnlinksKeys(): void
    {
        $tags = ['t1', 't2'];
        $t1 = 'p:t1';
        $t2 = 'p:t2';

        $this->connection->expects($this->exactly(2))
            ->method('exists')
            ->willReturnMap([[ $t1, 1 ], [ $t2, 1 ]]);

        // For t1: multi -> smembers -> del -> exec returns members
        // For t2: same flow
        $order = 0;
        $this->connection->expects($this->exactly(2))->method('multi')
            ->willReturnCallback(function () use (&$order): void { $order++; });

        $this->connection->expects($this->exactly(2))->method('smembers')
            ->willReturnOnConsecutiveCalls(['k1', 'k2'], ['k2', 'k3']);

        $this->connection->expects($this->exactly(2))->method('del')
            ->willReturnCallback(function (string $tagKey) use ($t1, $t2): void {
                $this->assertContains($tagKey, [$t1, $t2]);
            });

        $this->connection->expects($this->exactly(2))->method('exec')
            ->willReturnOnConsecutiveCalls([
                // members array is at [0] in Invalidator
                ['k1', 'k2'], 1
            ], [
                ['k2', 'k3'], 1
            ]);

        // After collecting, deleteKeys prefers unlink with all unique keys
        $this->connection->expects($this->once())
            ->method('unlink')
            ->willReturnCallback(function (): void {
                $args = func_get_args();
                $this->assertEqualsCanonicalizing(['k1', 'k2', 'k3'], $args);
            });

        $invalidator = new Invalidator($this->redis);
        $deleted = $invalidator->invalidate($tags);

        // Return value are the unique hashes collected
        $this->assertEqualsCanonicalizing(['k1', 'k2', 'k3'], $deleted);
    }

    public function testInvalidateFallsBackToDelWhenUnlinkFails(): void
    {
        $tags = ['t'];
        $t = 'p:t';

        $this->connection->expects($this->once())
            ->method('exists')
            ->with($t)
            ->willReturn(1);

        $this->connection->expects($this->once())->method('multi');
        $this->connection->expects($this->once())
            ->method('smembers')
            ->with($t)
            ->willReturn(['ka', 'kb']);
        // We'll assert tag DEL via unified callback below
        $this->connection->expects($this->once())
            ->method('exec')
            ->willReturn([
                ['ka', 'kb'], 1
            ]);

        // Make unlink throw to trigger DEL fallback
        $this->connection->expects($this->once())
            ->method('unlink')
            ->willReturnCallback(function (): void {
                throw new class extends \Exception implements Throwable {};
            });

        // Implementation deletes keys individually on unlink failure; there is also a prior tag DEL.
        // Use a single expectation to capture both.
        $deleted = [];
        $tagDelSeen = 0;
        $this->connection->expects($this->any())
            ->method('del')
            ->willReturnCallback(function (...$args) use (&$deleted, &$tagDelSeen, $t): void {
                // Tag deletion is called with the tag key only
                if (count($args) === 1 && $args[0] === $t) {
                    $tagDelSeen++;
                    return;
                }
                // In bulk-del case, args may be ['ka','kb']; in per-key, it's ['ka'] etc.
                foreach ($args as $arg) {
                    if (in_array($arg, ['ka', 'kb'], true)) {
                        $deleted[] = $arg;
                    }
                }
            });

        $invalidator = new Invalidator($this->redis);
        $invalidator->invalidate($tags);
        $this->assertEqualsCanonicalizing(['ka', 'kb'], $deleted);
        $this->assertSame(1, $tagDelSeen);
    }
}