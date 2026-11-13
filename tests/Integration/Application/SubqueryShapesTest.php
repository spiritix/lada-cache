<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\Automation;
use Spiritix\LadaCache\Tests\Database\Models\Application\AutomationRun;
use Spiritix\LadaCache\Tests\Database\Models\Application\Text;
use Spiritix\LadaCache\Tests\Database\Models\Application\Workspace;

final class SubqueryShapesTest extends ApplicationShapeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('automations')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'a'],
            ['id' => 2, 'workspace_id' => 1, 'name' => 'b'],
            ['id' => 3, 'workspace_id' => 2, 'name' => 'c'],
        ]);
        DB::table('automation_trigger_events')->insert([['automation_id' => 1, 'event' => 'created']]);
        DB::table('automation_runs')->insert([
            ['id' => 1, 'automation_id' => 1, 'trigger' => 'manual'],
            ['id' => 2, 'automation_id' => 1, 'trigger' => 'schedule'],
            ['id' => 3, 'automation_id' => 2, 'trigger' => 'manual'],
        ]);
    }

    public function testWhereNotExistsOrWhereExistsOverASubqueryIsRefreshedByItsTable(): void
    {
        $withCreatedEvent = fn (): array => Automation::query()
            ->where(fn ($query) => $query
                ->whereNotExists(fn ($sub) => $sub->from('automation_trigger_events')->whereColumn('automation_trigger_events.automation_id', 'automations.id'))
                ->orWhereExists(fn ($sub) => $sub->from('automation_trigger_events')->whereColumn('automation_trigger_events.automation_id', 'automations.id')->where('event', 'created')))
            ->orderBy('automations.id')
            ->pluck('automations.name')
            ->all();

        $this->assertCachedThenRefreshed(
            $withCreatedEvent,
            fn () => DB::table('automation_trigger_events')->insert(['automation_id' => 3, 'event' => 'archived']),
        );
    }

    public function testGroupedSelectRawMaxFollowedByFindManyIsRefreshed(): void
    {
        $newestPerTrigger = fn (): array => AutomationRun::query()
            ->whereIn('automation_id', [1, 2])
            ->select('automation_runs.automation_id')
            ->selectRaw('MAX(automation_runs.id) AS id')
            ->groupBy('automation_runs.automation_id', 'automation_runs.trigger')
            ->orderBy('automation_id')
            ->get()
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertCachedThenRefreshed(
            fn () => DB::table('automation_runs')->whereIn('id', $newestPerTrigger())->pluck('trigger')->sort()->values()->all(),
            fn () => DB::table('automation_runs')->insert(['id' => 4, 'automation_id' => 2, 'trigger' => 'schedule']),
        );
    }

    public function testWhereInSubqueryOverASecondTableIsRefreshedByThatTable(): void
    {
        DB::table('suites')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 's1'],
            ['id' => 2, 'workspace_id' => 3, 'name' => 's2'],
        ]);

        $workspacesWithSuites = fn (): array => Workspace::query()
            ->whereIn('workspaces.id', fn ($query) => $query->select('suites.workspace_id')->from('suites'))
            ->pluck('workspaces.name')
            ->sort()
            ->values()
            ->all();

        $this->assertCachedThenRefreshed(
            $workspacesWithSuites,
            fn () => DB::table('suites')->insert(['id' => 3, 'workspace_id' => 2, 'name' => 's3']),
        );
    }

    public function testSubqueryOverTheSameTableIsRefreshedByAnotherRow(): void
    {
        $workspacesWithMembers = fn (): array => Workspace::query()
            ->whereIn('workspaces.id', fn ($query) => $query->select('members.workspace_id')->from('members'))
            ->pluck('workspaces.id')
            ->sort()
            ->values()
            ->all();

        $this->assertCachedThenRefreshed(
            fn () => DB::table('members')->whereIn('workspace_id', $workspacesWithMembers())->count(),
            fn () => DB::table('members')->insert(['id' => 9, 'workspace_id' => 3, 'name' => 'eve', 'logins' => 0]),
        );
    }

    public function testSelectSubColumnIsRefreshedByTheSubqueryTable(): void
    {
        $counts = fn (): array => Workspace::query()
            ->select(['workspaces.id'])
            ->selectSub(fn ($query) => $query->selectRaw('count(*)')->from('members')->whereColumn('members.workspace_id', 'workspaces.id'), 'member_count')
            ->orderBy('workspaces.id')
            ->get()
            ->pluck('member_count')
            ->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => DB::table('members')->insert(['id' => 10, 'workspace_id' => 1, 'name' => 'fay', 'logins' => 2]),
        );
    }

    public function testWhereHasCountAndDeleteOverAMissingTranslationIsRefreshed(): void
    {
        DB::table('texts')->insert([['checksum' => 'aaa', 'content' => 'one'], ['checksum' => 'bbb', 'content' => 'two']]);
        DB::table('text_translations')->insert([['id' => 1, 'checksum' => 'aaa', 'locale' => 'en', 'content' => 'uno']]);
        DB::table('text_acceptances')->insert([['id' => 1, 'checksum' => 'aaa', 'member_id' => 1]]);

        $orphans = fn (): array => Text::query()->whereDoesntHave('translations')->whereDoesntHave('acceptances')->pluck('texts.checksum')->sort()->values()->all();

        $this->assertCachedThenRefreshed(
            $orphans,
            fn () => DB::table('text_translations')->insert(['id' => 2, 'checksum' => 'bbb', 'locale' => 'en', 'content' => 'dos']),
        );
    }

    public function testWithCountOverARelationIsRefreshedByAChildWrite(): void
    {
        $counts = fn (): array => Workspace::query()->withCount('members')->orderBy('workspaces.id')->get()->pluck('members_count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => DB::table('members')->insert(['id' => 11, 'workspace_id' => 3, 'name' => 'gil', 'logins' => 0]),
        );
    }

    public function testWithCountOverAModelWithoutTheTraitIsRefreshedByItsWrites(): void
    {
        $counts = fn (): array => Workspace::query()->withCount('templates')->orderBy('workspaces.id')->get()->pluck('templates_count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => DB::table('templates')->insert(['id' => 1, 'workspace_id' => 2, 'name' => 't', 'sort' => 0, 'active' => true]),
        );
    }
}
