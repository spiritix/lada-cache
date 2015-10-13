<?php

namespace Spiritix\LadaCache\Tests;

use Spiritix\LadaCache\Cache;

class CacheTest extends \PHPUnit_Framework_TestCase
{
    public function testInstanceCreation()
    {
        $cache = new Cache();
        $this->assertTrue($cache instanceof Cache);
    }
}