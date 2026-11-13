<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Cache;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Database\Models\License;
use Spiritix\LadaCache\Tests\TestCase;

class PrimaryKeyScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['lada-cache.consider_rows' => true]);
        License::query()->create(['code' => '1001', 'holder' => 'holder']);
        DB::table('license_renewals')->insert(['id' => 1, 'code' => '1001', 'note' => 'before']);
    }

    public function testJoinedTableFilteredByTheModelKeyNameIsInvalidatedByWriteByItsOwnKey(): void
    {
        $this->assertSame(['before'], $this->renewalNotesOf('1001'));

        DB::table('license_renewals')->where('id', 1)->update(['note' => 'after']);

        $this->assertSame(['after'], $this->renewalNotesOf('1001'));
    }

    public function testModelKeyPinsRowsOfTheModelTableOnly(): void
    {
        $query = License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('licenses.code', '1001')
            ->where('license_renewals.code', '1001')
            ->getQuery();

        $this->assertSame(['licenses' => ['1001']], (new Reflector($query))->getRows());
    }

    public function testConventionalKeyPinsRowsOfOtherTables(): void
    {
        $query = License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('license_renewals.id', 1)
            ->getQuery();

        $this->assertSame(['license_renewals' => [1]], (new Reflector($query))->getRows());
    }

    public function testQualifiedTableRowsAreNotPinnedOnAnUnqualifiedTableOfTheSameName(): void
    {
        $query = DB::table('archive.cars')
            ->join('cars', 'cars.id', '=', 'archive.cars.id')
            ->where('archive.cars.id', 1);

        $this->assertSame(['archive.cars' => [1]], (new Reflector($query))->getRows());
    }

    /**
     * @return array<int, string>
     */
    private function renewalNotesOf(string $code): array
    {
        return License::query()
            ->join('license_renewals', 'license_renewals.code', '=', 'licenses.code')
            ->where('license_renewals.code', $code)
            ->pluck('license_renewals.note')
            ->all();
    }
}
