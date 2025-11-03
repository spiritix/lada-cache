<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Database;

use Illuminate\Database\Connection as IlluminateConnection;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

class ConnectionProxyTest extends TestCase
{
    public function testProxiesDriverSpecificMethodsToBaseConnection(): void
    {
        // Get the current default connection (driver varies by env)
        $connection = DB::connection();

        // It must still be an Illuminate connection (driver-specific subclass)
        $this->assertInstanceOf(IlluminateConnection::class, $connection);

        // Call a driver-specific method available on driver connections
        // (Laravel 12 provides getDriverTitle() on each driver connection)
        $this->assertTrue(method_exists($connection, 'getDriverTitle'));
        $driverTitle = $connection->getDriverTitle();
        $this->assertIsString($driverTitle);
        $this->assertNotEmpty($driverTitle);
    }
}
