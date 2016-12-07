<?php

namespace Spiritix\LadaCache\Tests\Console;

use Spiritix\LadaCache\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    public function testDisable()
    {
        $cache = app()->make('lada.cache');
        $cache->set('key', ['tag'], 'data');

        $this->artisan('lada-cache:flush');

        $this->assertFalse($cache->has('key'));
    }
}