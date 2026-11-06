<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\TestCase;

final class RawTableReferencesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('owners')->insert(['id' => 1, 'name' => 'alice']);
    }

    public function testWhereRawSubqueryRefreshesAfterWriteToItsTable(): void
    {
        $withPets = fn (): array => DB::table('owners')->whereRaw('exists (select 1 from pets where pets.owner_id = owners.id)')->pluck('id')->all();
        $this->assertSame([], $withPets());

        DB::table('pets')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'rex']);

        $this->assertSame([1], array_map('intval', $withPets()));
    }

    public function testSelectRawSubqueryRefreshesAfterWriteToItsTable(): void
    {
        $petCount = fn (): int => (int) DB::table('owners')->selectRaw('(select count(*) from pets) as pet_count')->value('pet_count');
        $this->assertSame(0, $petCount());

        DB::table('pets')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'rex']);

        $this->assertSame(1, $petCount());
    }

    public function testRawJoinRefreshesAfterWriteToTheJoinedTable(): void
    {
        $petNames = fn (): array => DB::table('owners')->join(DB::raw('pets'), 'pets.owner_id', '=', 'owners.id')->pluck('pets.name')->all();
        $this->assertSame([], $petNames());

        DB::table('pets')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'rex']);

        $this->assertSame(['rex'], $petNames());
    }

    public function testFromRawRefreshesAfterWriteToItsTable(): void
    {
        $names = fn (): array => DB::query()->fromRaw(DB::connection()->getTablePrefix().'pets')->pluck('name')->all();
        $this->assertSame([], $names());

        DB::table('pets')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'rex']);

        $this->assertSame(['rex'], $names());
    }
}
