<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Database\Events\MigrationEnded;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Redis;
use Spiritix\LadaCache\Tests\TestCase;

final class MigrationsEndedTest extends TestCase
{
    public function testCompletedMigrationRetiresCacheEvenWhenTheBatchNeverFinishes(): void
    {
        $this->seedCachedName('pre-migration');
        $generationBefore = $this->currentGenerationId();

        $this->renameOutsideLada('post-migration');
        event(new MigrationEnded(new class extends Migration {}, 'up'));

        $this->assertNotSame($generationBefore, $this->currentGenerationId());
        $this->assertSame('post-migration', DB::table('cars')->where('id', 1)->value('name'));
    }

    public function testMigrationsEndedPrunesRetiredGenerationsButKeepsTheCurrentOne(): void
    {
        $this->seedCachedName('pre-migration');
        $redis = new Redis;
        $retired = $this->currentGenerationId();
        event(new MigrationEnded(new class extends Migration {}, 'up'));
        $current = $this->currentGenerationId();

        event(new MigrationsEnded('up'));

        $this->assertSame($current, $this->currentGenerationId());
        $this->assertSame([], $redis->keys($redis->prefix($retired.':*')));
    }

    private function currentGenerationId(): string
    {
        $redis = new Redis;

        return (string) $redis->get($redis->prefix('generation'));
    }

    private function seedCachedName(string $name): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => $name, 'engine_id' => null, 'driver_id' => null]);
        $this->assertSame($name, DB::table('cars')->where('id', 1)->value('name'));
    }

    private function renameOutsideLada(string $name): void
    {
        DB::connection()->getPdo()->prepare('update cars set name = ? where id = 1')->execute([$name]);
    }
}
