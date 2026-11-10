<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\AssistantPrompt;
use Spiritix\LadaCache\Tests\Database\Models\Application\Automation;
use Spiritix\LadaCache\Tests\Database\Models\Application\FeatureMetric;
use Spiritix\LadaCache\Tests\Database\Models\Application\Workspace;
use Spiritix\LadaCache\Tests\Database\Models\Application\WorkspaceGroup;

final class RawAndWriteShapesTest extends ApplicationShapeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('prompts')->insert([
            ['id' => 1, 'type' => 'assistant', 'body' => 'hello'],
            ['id' => 2, 'type' => 'system', 'body' => 'rules'],
        ]);
    }

    public function testOrderByRawBooleanExpressionIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => AssistantPrompt::query()->orderByRaw('prompts.id > 1')->pluck('prompts.body')->all(),
            fn () => DB::table('prompts')->where('id', 1)->update(['body' => 'hi']),
        );
    }

    public function testRawStatementWriteInvalidatesTheTable(): void
    {
        $names = fn (): array => Workspace::query()->orderBy('workspaces.id')->pluck('workspaces.name')->all();

        $this->assertCachedThenRefreshed(
            $names,
            fn () => DB::statement("update workspaces set name = 'renamed' where id = 3"),
        );
    }

    public function testRawMultiRowCaseUpdateOfSortInvalidatesTheTable(): void
    {
        DB::table('templates')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'one', 'sort' => 0, 'active' => true],
            ['id' => 2, 'workspace_id' => 1, 'name' => 'two', 'sort' => 1, 'active' => true],
        ]);

        $this->assertCachedThenRefreshed(
            fn (): array => DB::table('templates')->orderByRaw('sort asc')->pluck('name')->all(),
            fn () => DB::statement('update templates set sort = case id when 1 then 1 when 2 then 0 end where id in (1, 2)'),
        );
    }

    public function testIncrementOrCreateIsRefreshed(): void
    {
        $counts = fn (): array => FeatureMetric::query()->orderBy('feature')->pluck('count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => FeatureMetric::query()->incrementOrCreate(['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload'], step: 2),
        );
    }

    public function testIncrementEachIsRefreshed(): void
    {
        FeatureMetric::query()->create(['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload', 'count' => 1]);

        $counts = fn (): array => FeatureMetric::query()->orderBy('feature')->pluck('count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => FeatureMetric::query()->whereKey(1)->incrementEach(['count' => 4]),
        );
    }

    public function testTouchingAParentIsRefreshed(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => Workspace::query()->orderBy('workspaces.id')->pluck('workspaces.updated_at')->all(),
            fn () => Workspace::query()->find(3)?->touch(),
        );
    }

    public function testMassUpdateWithALimitIsRefreshed(): void
    {
        DB::table('automations')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'a'],
            ['id' => 2, 'workspace_id' => 1, 'name' => 'b'],
        ]);

        $this->assertCachedThenRefreshed(
            fn (): array => Automation::query()->orderBy('automations.id')->pluck('automations.name')->all(),
            fn () => Automation::query()->where('workspace_id', 1)->limit(1)->update(['name' => 'renamed']),
        );
    }

    public function testUpdateInsideATransactionIsVisibleAfterCommit(): void
    {
        $this->assertCachedThenRefreshed(
            fn (): array => WorkspaceGroup::query()->orderBy('workspace_groups.id')->pluck('workspace_groups.name')->all(),
            fn () => DB::transaction(fn () => DB::table('workspace_groups')->where('id', 2)->update(['name' => 'enterprise'])),
        );
    }

    public function testRolledBackTransactionLeavesTheCacheAsItWas(): void
    {
        $before = WorkspaceGroup::query()->orderBy('workspace_groups.id')->pluck('workspace_groups.name')->all();

        try {
            DB::transaction(function (): void {
                DB::table('workspace_groups')->where('id', 2)->update(['name' => 'enterprise']);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, WorkspaceGroup::query()->orderBy('workspace_groups.id')->pluck('workspace_groups.name')->all());
        $this->assertSame($before, $this->uncached(fn () => WorkspaceGroup::query()->orderBy('workspace_groups.id')->pluck('workspace_groups.name')->all()));
    }

    public function testWriteThroughAPhogetPdoInAnotherConnectionIsNotSeenButTheCommandFixesIt(): void
    {
        $this->assertSame('gamma', Workspace::query()->find(3)?->name);

        DB::connection()->getPdo()->exec("update workspaces set name = 'renamed' where id = 3");
        $this->assertSame('gamma', Workspace::query()->find(3)?->name, 'Writes bypassing Laravel are invisible to the cache by design');

        $this->artisan('lada-cache:invalidate', ['tables' => ['workspaces']])->assertExitCode(0);

        $this->assertSame('renamed', Workspace::query()->find(3)?->name);
    }
}
