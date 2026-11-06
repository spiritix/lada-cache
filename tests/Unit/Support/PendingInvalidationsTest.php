<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit\Support;

use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use Spiritix\LadaCache\Support\Invalidation;
use Spiritix\LadaCache\Support\PendingInvalidations;
use Spiritix\LadaCache\Support\WriteKind;

final class PendingInvalidationsTest extends TestCase
{
    public function testStateBelongsToTheConnectionObjectNotItsName(): void
    {
        $pending = new PendingInvalidations;
        $purged = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);
        $reconnected = new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', '', ['name' => 'sqlite']);

        $pending->add($purged, Invalidation::of(['a', 'b']));
        $pending->add($purged, Invalidation::of(['a'])->withChangesOf('db', 'sqlite', WriteKind::Modify, ['cars']));
        $pending->add($purged, Invalidation::everything());

        $this->assertTrue($pending->take($reconnected)->isEmpty());
        $taken = $pending->take($purged);
        $this->assertSame(['a', 'b'], $taken->tags());
        $this->assertSame(['db' => ['m:cars']], $taken->cascadeSources());
        $this->assertTrue($taken->retiresEverything);
        $this->assertTrue($pending->take($purged)->isEmpty());
    }
}
