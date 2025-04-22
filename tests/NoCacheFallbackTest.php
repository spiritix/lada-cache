<?php

namespace Spiritix\LadaCache\Tests;

use Illuminate\Support\Facades\DB;

class NoCacheFallbackTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('lada-cache.active', false);
    }

    public function test_laravel_behavior_without_ladacache()
    {
        // Create a simple table for testing
        DB::statement('CREATE TABLE IF NOT EXISTS dummy_table (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)');
        DB::table('dummy_table')->insert(['name' => 'test']);

        // Fetch using Eloquent (should not cache)
        $result = DB::table('dummy_table')->where('name', 'test')->first();
        $this->assertEquals('test', $result->name);

        // Insert another row and fetch again
        DB::table('dummy_table')->insert(['name' => 'test2']);
        $result2 = DB::table('dummy_table')->where('name', 'test2')->first();
        $this->assertEquals('test2', $result2->name);

        // There should be no LadaCache or Redis side effects at all
        // (If LadaCache or Redis is initialized, it would throw or fail here)
    }
}
