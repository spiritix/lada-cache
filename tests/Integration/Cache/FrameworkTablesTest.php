<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Manager;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Support\FrameworkTables;
use Spiritix\LadaCache\Tests\TestCase;

final class FrameworkTablesTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.table', 'web_sessions');
        $app['config']->set('lada-cache.exclude_tables', ['audit_log']);
    }

    public function testDatabaseBackedFrameworkTablesAreNotCached(): void
    {
        $this->assertFalse((new Manager(new Reflector(DB::table('web_sessions')->where('id', 'abc'))))->shouldCache());
        $this->assertTrue((new Manager(new Reflector(DB::table('cars')->where('id', 1))))->shouldCache());
    }

    public function testConfiguredExclusionsAreKept(): void
    {
        $this->assertContains('audit_log', config('lada-cache.exclude_tables'));
        $this->assertContains('web_sessions', config('lada-cache.exclude_tables'));
    }

    public function testOnlyStoresUsingTheDatabaseContributeTables(): void
    {
        config([
            'session.driver' => 'redis',
            'cache.stores' => ['db' => ['driver' => 'database', 'table' => 'app_cache', 'lock_table' => 'app_locks'], 'mem' => ['driver' => 'array']],
            'queue.connections' => ['database' => ['driver' => 'database', 'table' => 'queue_jobs'], 'redis' => ['driver' => 'redis']],
            'queue.failed' => ['driver' => 'null'],
            'queue.batching' => [],
        ]);

        $this->assertSame(['app_cache', 'app_locks', 'queue_jobs'], FrameworkTables::volatile());
    }
}
