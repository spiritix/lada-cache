<?php

namespace Spiritix\LadaCache\Tests\Console;

use Illuminate\Support\Facades\Artisan;
use Spiritix\LadaCache\Tests\TestCase;

class FlushCommandTest extends TestCase
{
    public function testDisable()
    {
        // TODO uncomment this as soon as issue #4 is solved

        //$cache = app()->make('lada.cache');
        //$cache->set('key', ['tag'], 'data');
        //
        //$this->artisan('lada-cache:flush');
        //
        //$this->assertFalse($cache->has('key'));
    }
}