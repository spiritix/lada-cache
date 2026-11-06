<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\PendingInvalidations;

final class PendingInvalidationsTest extends TestCase
{
    public function testStateBelongsToTheConnectionObjectNotItsName(): void
    {
        $pending = new PendingInvalidations();
        $purged = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);
        $reconnected = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);

        $pending->addTags($purged, ['a', 'b', 'a']);
        $pending->requestRetire($purged);

        $this->assertSame(['tags' => [], 'retire' => false], $pending->take($reconnected));
        $this->assertSame(['tags' => ['a', 'b'], 'retire' => true], $pending->take($purged));
        $this->assertSame(['tags' => [], 'retire' => false], $pending->take($purged));
    }
}
