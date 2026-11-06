<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\PendingInvalidations;

final class PendingInvalidationsTest extends TestCase
{
    public function testStateBelongsToTheConnectionObjectNotItsName(): void
    {
        $pending = new PendingInvalidations;
        $purged = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);
        $reconnected = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);

        $pending->add($purged, Invalidation::of(['a', 'b'], 'db', 'sqlite', [], 0));
        $pending->add($purged, Invalidation::of(['a'], 'db', 'sqlite', ['cars'], 0));
        $pending->add($purged, Invalidation::everything());

        $this->assertTrue($pending->take($reconnected)->isEmpty());
        $taken = $pending->take($purged);
        $this->assertSame(['a', 'b'], $taken->tags());
        $this->assertSame(['db' => ['cars']], $taken->cascadeSources());
        $this->assertTrue($taken->retiresEverything);
        $this->assertTrue($pending->take($purged)->isEmpty());
    }
}
