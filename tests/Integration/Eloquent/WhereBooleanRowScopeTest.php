<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\TestCase;

/**
 * Wheres that widen the row set (top-level OR, whereNot) must not scope a query to the rows
 * of its primary-key condition, otherwise writes to the other rows leave it stale.
 */
final class WhereBooleanRowScopeTest extends TestCase
{
    public function test_or_where_read_is_refreshed_by_insert_matching_the_or_branch(): void
    {
        $anchor = Driver::create(['name' => 'anchor']);
        $count = fn (): int => Driver::query()->where('id', $anchor->id)->orWhere('name', 'probe')->count();

        $this->assertSame(1, $count());

        Driver::create(['name' => 'probe']);

        $this->assertSame(2, $count());
    }

    public function test_upper_case_or_read_is_refreshed_by_insert_matching_the_or_branch(): void
    {
        $anchor = Driver::create(['name' => 'anchor']);
        $count = fn (): int => Driver::query()->where('id', $anchor->id)->where('name', '=', 'probe', 'OR')->count();

        $this->assertSame(1, $count());

        Driver::create(['name' => 'probe']);

        $this->assertSame(2, $count());
    }

    public function test_or_where_update_refreshes_rows_matched_by_the_or_branch(): void
    {
        $anchor = Driver::create(['name' => 'anchor']);
        $matched = Driver::create(['name' => 'probe']);

        $this->assertSame('probe', Driver::find($matched->id)?->name);

        Driver::query()->where('id', $anchor->id)->orWhere('name', 'probe')->update(['name' => 'renamed']);

        $this->assertSame('renamed', Driver::find($matched->id)?->name);
    }

    public function test_where_not_read_is_refreshed_by_insert(): void
    {
        $anchor = Driver::create(['name' => 'anchor']);
        $count = fn (): int => Driver::query()->whereNot('id', $anchor->id)->where('name', 'probe')->count();

        $this->assertSame(0, $count());

        Driver::create(['name' => 'probe']);

        $this->assertSame(1, $count());
    }
}
