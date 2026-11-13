<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class InvalidateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('cars')->insert(['id' => 1, 'name' => 'before']);
        DB::table('drivers')->insert(['id' => 1, 'name' => 'before']);
    }

    public function testInvalidatesRowAndTableEntriesOfTheNamedTablesOnly(): void
    {
        $this->assertSame('before', Car::query()->find(1)?->name);
        $this->assertSame(['before'], DB::table('cars')->pluck('name')->all());
        $this->assertSame('before', DB::table('drivers')->where('id', 1)->value('name'));
        $this->writeBypassingLaravel("update cars set name = 'after'");
        $this->writeBypassingLaravel("update drivers set name = 'after'");

        $this->artisan('lada-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutput('Invalidated 2 cached entries of: cars')
            ->assertExitCode(0);

        $this->assertSame('after', Car::query()->find(1)?->name);
        $this->assertSame(['after'], DB::table('cars')->pluck('name')->all());
        $this->assertSame('before', DB::table('drivers')->where('id', 1)->value('name'));
    }

    public function testInvalidatesTableEntriesWithoutRowLevelTags(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $this->assertSame('before', Car::query()->find(1)?->name);
        $this->writeBypassingLaravel("update cars set name = 'after'");

        $this->artisan('lada-cache:invalidate', ['tables' => ['cars']])->assertExitCode(0);

        $this->assertSame('after', Car::query()->find(1)?->name);
    }

    public function testRejectsUnknownTablesWithoutInvalidatingAnything(): void
    {
        $this->assertSame('before', Car::query()->find(1)?->name);
        $this->writeBypassingLaravel("update cars set name = 'after'");

        $this->artisan('lada-cache:invalidate', ['tables' => ['cars', 'carz']])
            ->expectsOutput('Unknown tables on connection ['.DB::getDefaultConnection().']: carz')
            ->assertExitCode(1);

        $this->assertSame('before', Car::query()->find(1)?->name);
    }

    public function testFailsWhenRedisIsUnreachable(): void
    {
        $this->useUnreachableRedis();

        $this->artisan('lada-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutputToContain('Failed to invalidate Lada Cache: Connection refused')
            ->assertExitCode(1);
    }

    public function testFailsWhenDisabled(): void
    {
        config(['lada-cache.active' => false]);

        $this->artisan('lada-cache:invalidate', ['tables' => ['cars']])
            ->expectsOutput('Lada Cache is disabled. Nothing was invalidated.')
            ->assertExitCode(1);
    }

    private function writeBypassingLaravel(string $sql): void
    {
        DB::connection()->getPdo()->exec($sql);
    }
}
