<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\Workspace;

/**
 * Reads whose shape depends on database functions. The cache keys and tags them like any other
 * read, so a write to a table they name has to invalidate them, and a read that cannot be cached
 * safely must not be.
 */
final class FunctionShapesTest extends ApplicationShapeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('workspaces')->where('id', 1)->update(['token_limit' => 10]);
    }

    public function testOrderByRawWithAFunctionIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => Workspace::query()->orderByRaw('COALESCE(workspaces.token_limit, 0) desc, workspaces.id')->pluck('workspaces.id')->all(),
            fn () => DB::table('workspaces')->where('id', 2)->update(['token_limit' => 500]),
        );
    }

    public function testSelectRawWithACaseExpressionIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => Workspace::query()
                ->select('workspaces.id')
                ->selectRaw('case when workspaces.token_limit is null then 0 else workspaces.token_limit end as limit_or_zero')
                ->orderBy('workspaces.id')
                ->get()
                ->pluck('limit_or_zero')
                ->all(),
            fn () => DB::table('workspaces')->where('id', 2)->update(['token_limit' => 42]),
        );
    }

    public function testWhereRawNamingAnotherTableIsRefreshedByThatTable(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => Workspace::query()
                ->whereRaw('exists (select 1 from members where members.workspace_id = workspaces.id and members.logins > ?)', [5])
                ->pluck('workspaces.name')
                ->sort()
                ->values()
                ->all(),
            fn () => DB::table('members')->where('id', 1)->update(['logins' => 9]),
        );
    }

    public function testReadCallingCurrentTimestampIsNeverCached(): void
    {
        $count = fn (): int => Workspace::query()->where('workspaces.created_at', '<', DB::raw('current_timestamp'))->count();

        $count();
        $before = $this->selectsDuring($count);

        $this->assertSame(1, $before, 'A read depending on the current time must reach the database every time');
    }

    public function testReadCallingRandIsNeverCached(): void
    {
        $this->requireDatabaseDriver('mysql');

        $read = fn (): array => Workspace::query()->orderByRaw('rand()')->pluck('workspaces.id')->all();

        $read();
        $this->assertSame(1, $this->selectsDuring($read));
    }

    public function testAggregatesAreRefreshedByWrites(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => [
                Workspace::query()->count(),
                Workspace::query()->max('workspaces.id'),
                Workspace::query()->sum('workspaces.group_id'),
                Workspace::query()->exists(),
            ],
            fn () => DB::table('workspaces')->insert(['id' => 4, 'group_id' => 2, 'name' => 'delta', 'active' => true, 'token_limit' => 5]),
        );
    }

    public function testPluckAndValueAreRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => [Workspace::query()->where('workspaces.id', 2)->value('workspaces.name'), Workspace::query()->pluck('workspaces.name', 'workspaces.id')->all()],
            fn () => DB::table('workspaces')->where('id', 2)->update(['name' => 'renamed']),
        );
    }
}
