<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Database;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Database\Connection as LadaConnection;
use Spiritix\LadaCache\Tests\TestCase;

class ConnectionProxyTest extends TestCase
{
    public function testProxiesDriverSpecificMethodsToBaseConnection(): void
    {
        // Get the actual connection (SQLite in tests)
        $connection = DB::connection();

        // Verify it's a Lada connection
        $this->assertInstanceOf(LadaConnection::class, $connection);

        // Call a driver-specific method that exists on SQLiteConnection
        // getDriverTitle() is present on all driver-specific connections
        $driverTitle = $connection->getDriverTitle();

        $this->assertIsString($driverTitle);
        $this->assertNotEmpty($driverTitle);
    }
}
