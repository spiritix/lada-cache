<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\Workspace;

final class JoinShapesTest extends ApplicationShapeTestCase
{
    private function latestLoginsPerWorkspace(): array
    {
        return Workspace::query()
            ->select(['workspaces.id', 'members_sub.most_logins', 'workspace_groups.name as group_name'])
            ->joinSub(
                DB::table('members')->selectRaw('members.workspace_id, MAX(members.logins) as most_logins')->groupBy('members.workspace_id'),
                'members_sub',
                'workspaces.id',
                '=',
                'members_sub.workspace_id',
            )
            ->join('workspace_groups', 'workspaces.group_id', '=', 'workspace_groups.id')
            ->join('admin_workspace', fn (JoinClause $join) => $join->on('workspaces.id', '=', 'admin_workspace.workspace_id')->where('admin_workspace.admin_id', '=', 1))
            ->orderByRaw('most_logins desc')
            ->get()
            ->map(fn (Workspace $workspace): array => $workspace->only(['id', 'most_logins', 'group_name']))
            ->all();
    }

    public function testJoinSubOfAggregateIsRefreshedByWritesToTheAggregatedTable(): void
    {
        DB::table('admin_workspace')->insert([['admin_id' => 1, 'workspace_id' => 1], ['admin_id' => 1, 'workspace_id' => 2]]);

        $this->assertCachedThenRefreshed(
            fn () => $this->latestLoginsPerWorkspace(),
            fn () => DB::table('members')->where('id', 3)->update(['logins' => 99]),
        );
    }

    public function testJoinSubIsRefreshedByWritesToTheJoinClosureTable(): void
    {
        DB::table('admin_workspace')->insert(['admin_id' => 1, 'workspace_id' => 1]);

        $this->assertCachedThenRefreshed(
            fn () => $this->latestLoginsPerWorkspace(),
            fn () => DB::table('admin_workspace')->insert(['admin_id' => 1, 'workspace_id' => 2]),
        );
    }

    public function testJoinSubIsRefreshedByRowWritesToTheJoinedTable(): void
    {
        DB::table('admin_workspace')->insert(['admin_id' => 1, 'workspace_id' => 2]);

        $this->assertCachedThenRefreshed(
            fn () => $this->latestLoginsPerWorkspace(),
            fn () => DB::table('workspace_groups')->where('id', 2)->update(['name' => 'renamed']),
        );
    }

    public function testCountOverRawWrappedQueryIsRefreshed(): void
    {
        $count = fn (): int => DB::query()->fromRaw('('.Workspace::query()->where('workspaces.id', '>', 0)->toRawSql().') count_row_table')->count();

        $this->assertCachedThenRefreshed($count, fn () => DB::table('workspaces')->insert(['id' => 90, 'group_id' => 1, 'name' => 'delta', 'active' => true]));
    }

    public function testJoinClosureWithBoundValueIsRefreshedByWritesToTheJoinedRow(): void
    {
        $this->assertCachedThenRefreshed(
            fn () => DB::table('members')
                ->join('workspaces', fn (JoinClause $join) => $join->on('workspaces.id', '=', 'members.workspace_id')->where('workspaces.active', '=', true))
                ->where('members.name', 'bob')
                ->first()?->name,
            fn () => Workspace::query()->whereKey(2)->update(['active' => false]),
        );
    }

    public function testAggregateJoinGroupedAndOrderedByAliasIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn () => Workspace::query()
                ->select('workspaces.id', 'workspaces.name', DB::raw('MAX(members.logins) as most_logins'))
                ->join('members', 'members.workspace_id', '=', 'workspaces.id')
                ->where('workspaces.active', 1)
                ->groupBy('workspaces.id', 'workspaces.name')
                ->orderBy('most_logins', 'desc')
                ->take(5)
                ->get()
                ->toArray(),
            fn () => DB::table('members')->insert(['id' => 4, 'workspace_id' => 1, 'name' => 'dan', 'logins' => 50]),
        );
    }

    public function testLeftJoinWithOrAcrossTablesChunkedByQualifiedIdIsRefreshed(): void
    {
        $limited = function (): array {
            $ids = [];
            Workspace::query()
                ->select('workspaces.*')
                ->leftJoin('workspace_groups', 'workspaces.group_id', '=', 'workspace_groups.id')
                ->where(fn ($query) => $query->whereNotNull('workspaces.token_limit')->orWhereNotNull('workspace_groups.token_limit'))
                ->chunkById(1, function ($workspaces) use (&$ids): void {
                    foreach ($workspaces as $workspace) {
                        $ids[] = $workspace->id;
                    }
                }, 'workspaces.id', 'id');

            return $ids;
        };

        $this->assertCachedThenRefreshed($limited, fn () => DB::table('workspace_groups')->where('id', 1)->update(['token_limit' => 10]));
    }

    public function testWhereNotOnJoinedColumnIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn () => Workspace::query()
                ->join('workspace_groups', 'workspaces.group_id', '=', 'workspace_groups.id')
                ->whereNot('workspace_groups.package_key', '=', 'trial')
                ->pluck('workspaces.name')
                ->all(),
            fn () => DB::table('workspace_groups')->where('id', 1)->update(['package_key' => 'paid']),
        );
    }

    public function testAliasedSelfJoinIsRefreshedByWritesThroughEitherAlias(): void
    {
        $this->assertCachedThenRefreshed(
            fn () => DB::table('members as m')
                ->join('members as peer', 'peer.workspace_id', '=', 'm.workspace_id')
                ->where('m.id', 2)
                ->whereColumn('peer.id', '!=', 'm.id')
                ->pluck('peer.name')
                ->all(),
            fn () => DB::table('members')->where('id', 3)->update(['name' => 'carl']),
        );
    }
}
