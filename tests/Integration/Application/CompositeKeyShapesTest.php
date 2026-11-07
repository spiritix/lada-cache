<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\Automation;
use Spiritix\LadaCache\Tests\Database\Models\Application\AutomationTriggerEvent;
use Spiritix\LadaCache\Tests\Database\Models\Application\FeatureMetric;

final class CompositeKeyShapesTest extends ApplicationShapeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('automations')->insert([
            ['id' => 1, 'workspace_id' => 1, 'name' => 'a'],
            ['id' => 2, 'workspace_id' => 1, 'name' => 'b'],
        ]);
    }

    public function testMissingRowReadOfACompositeKeyTableRefreshesAfterInsertOrIgnore(): void
    {
        $events = fn (): array => AutomationTriggerEvent::query()->where('automation_id', 1)->pluck('event')->sort()->values()->all();

        $this->assertCachedThenRefreshed(
            $events,
            fn () => AutomationTriggerEvent::query()->insertOrIgnore([['automation_id' => 1, 'event' => 'created']]),
        );
    }

    public function testDeleteOnACompositeKeyTableRefreshesItsReads(): void
    {
        DB::table('automation_trigger_events')->insert([
            ['automation_id' => 1, 'event' => 'created'],
            ['automation_id' => 1, 'event' => 'deleted'],
        ]);

        $events = fn (): array => AutomationTriggerEvent::query()->where('automation_id', 1)->pluck('event')->sort()->values()->all();

        $this->assertCachedThenRefreshed(
            $events,
            fn () => AutomationTriggerEvent::query()->where('automation_id', 1)->where('event', 'created')->delete(),
        );
    }

    public function testInsertWithoutAKeyRefreshesAReadThatExpectedNoRows(): void
    {
        $between = fn (): array => Automation::query()->whereBetween('automations.id', [10, 20])->pluck('automations.name')->all();

        $this->assertCachedThenRefreshed(
            $between,
            fn () => Automation::query()->insert(['id' => 11, 'workspace_id' => 1, 'name' => 'c']),
        );
    }

    public function testIncrementAndDecrementAreRefreshed(): void
    {
        FeatureMetric::query()->create(['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload', 'count' => 5]);

        $counts = fn (): array => FeatureMetric::query()->orderBy('feature')->pluck('count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => FeatureMetric::query()->whereKey(1)->decrement('count', 2),
        );
    }

    public function testUpsertRefreshesReadsOfTheRowsItTouches(): void
    {
        $counts = fn (): array => FeatureMetric::query()->orderBy('feature')->pluck('count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => FeatureMetric::query()->upsert(
                [['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload', 'count' => 9]],
                ['workspace_id', 'member_id', 'feature'],
                ['count'],
            ),
        );
    }

    public function testUpdateOrCreateRefreshesReadsOfTheRow(): void
    {
        FeatureMetric::query()->create(['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload', 'count' => 1]);

        $counts = fn (): array => FeatureMetric::query()->orderBy('feature')->pluck('count')->all();

        $this->assertCachedThenRefreshed(
            $counts,
            fn () => FeatureMetric::query()->updateOrCreate(
                ['workspace_id' => 1, 'member_id' => 1, 'feature' => 'upload'],
                ['count' => 7],
            ),
        );
    }
}
