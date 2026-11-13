<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Application;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Tests\Database\Models\Application\Admin;
use Spiritix\LadaCache\Tests\Database\Models\Application\AutomationRun;
use Spiritix\LadaCache\Tests\Database\Models\Application\Element;
use Spiritix\LadaCache\Tests\Database\Models\Application\ProviderSetting;
use Spiritix\LadaCache\Tests\Database\Models\Application\Text;
use Spiritix\LadaCache\Tests\Database\Models\Application\TextTranslation;
use Spiritix\LadaCache\Tests\Database\Models\Application\Workspace;

final class RelationShapesTest extends ApplicationShapeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('automations')->insert(['id' => 1, 'workspace_id' => 1, 'name' => 'a']);
    }

    public function testEagerLoadingAMorphManyIsRefreshedByTheChildTable(): void
    {
        DB::table('elements')->insert(['id' => 1, 'name' => 'title']);
        DB::table('text_translations')->insert([
            'id' => 1,
            'checksum' => 'aaa',
            'locale' => 'en',
            'translatable_type' => Element::class,
            'translatable_id' => 1,
            'content' => 'hello',
        ]);

        $contents = fn (): array => Element::query()->with('translations')->get()->flatMap(fn (Element $element) => $element->translations->pluck('content'))->all();

        $this->assertCachedThenRefreshed(
            $contents,
            fn () => DB::table('text_translations')->where('id', 1)->update(['content' => 'goodbye']),
        );
    }

    public function testMorphToIsRefreshedByTheParentTable(): void
    {
        DB::table('elements')->insert(['id' => 1, 'name' => 'title']);
        DB::table('text_translations')->insert([
            'id' => 1,
            'checksum' => 'aaa',
            'locale' => 'en',
            'translatable_type' => Element::class,
            'translatable_id' => 1,
            'content' => 'hello',
        ]);

        $translated = fn (): array => TextTranslation::query()->with('translatable')->get()->map(fn (TextTranslation $translation) => $translation->translatable?->name)->all();

        $this->assertCachedThenRefreshed(
            $translated,
            fn () => DB::table('elements')->where('id', 1)->update(['name' => 'heading']),
        );
    }

    public function testBelongsToManyThroughAModelPivotIsRefreshedByThePivotWrite(): void
    {
        DB::table('languages')->insert([['id' => 1, 'code' => 'en'], ['id' => 2, 'code' => 'nl']]);
        DB::table('workspace_languages')->insert(['id' => 1, 'workspace_id' => 1, 'language_id' => 1, 'is_default' => true]);

        $default = fn (): ?string => Workspace::query()->find(1)?->languages()->where('workspace_languages.is_default', true)->first()?->code;

        $this->assertCachedThenRefreshed(
            fn (): array => [(string) $default()],
            fn () => DB::table('workspace_languages')->where('id', 1)->update(['language_id' => 2]),
        );
    }

    public function testPivotWritesThroughANonTraitParentInvalidateTraitReads(): void
    {
        DB::table('admins')->insert(['id' => 1, 'name' => 'root']);

        $this->assertCachedThenRefreshed(
            fn (): array => Admin::query()->find(1)->workspaces()->pluck('workspaces.name')->sort()->values()->all(),
            fn () => Admin::query()->find(1)->workspaces()->attach(2),
        );
    }

    public function testHasOneThroughIsRefreshedByTheThroughTable(): void
    {
        $workspaceOfRun = fn (): ?string => AutomationRun::query()->find(1)?->workspace?->name;

        DB::table('automation_runs')->insert(['id' => 1, 'automation_id' => 1, 'trigger' => 'manual']);
        $this->assertSame('alpha', $workspaceOfRun());

        DB::table('automations')->where('id', 1)->update(['workspace_id' => 2]);

        $this->assertSame('beta', $workspaceOfRun());
    }

    public function testStringKeyedModelIsRefreshedByFirstOrCreate(): void
    {
        $content = fn (): array => [Text::query()->find('abc')?->content];

        $this->assertCachedThenRefreshed(
            $content,
            fn () => Text::query()->firstOrCreate(['checksum' => 'abc'], ['content' => 'created']),
        );
    }

    public function testUuidKeyedModelIsRefreshedByUpdate(): void
    {
        $setting = ProviderSetting::query()->create(['workspace_id' => 1, 'provider' => 'openai', 'quota_exhausted_at' => null]);

        $exhausted = fn (): array => [ProviderSetting::query()->find($setting->uuid)?->quota_exhausted_at];

        $this->assertCachedThenRefreshed(
            $exhausted,
            fn () => ProviderSetting::query()->whereKey($setting->uuid)->update(['quota_exhausted_at' => now()]),
        );
    }

    public function testUuidKeysInWhereKeyAreRefreshedByRowWrites(): void
    {
        $first = ProviderSetting::query()->create(['workspace_id' => 1, 'provider' => 'openai', 'quota_exhausted_at' => null]);
        $second = ProviderSetting::query()->create(['workspace_id' => 1, 'provider' => 'azure', 'quota_exhausted_at' => null]);

        $providers = fn (): array => ProviderSetting::query()->whereKey([$first->uuid, $second->uuid])->pluck('provider')->sort()->values()->all();

        $this->assertCachedThenRefreshed(
            $providers,
            fn () => ProviderSetting::query()->whereKey($second->uuid)->update(['provider' => 'gemini']),
        );
    }
}
