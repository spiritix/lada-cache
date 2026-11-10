<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

class TaggerTest extends TestCase
{
    private function dbTagPrefix(): string
    {
        return 'tags:database:'.DB::connection()->getDatabaseName();
    }

    public function testLegacyDependenciesAddTheUnspecificTagOfTablesAReadIsPinnedTo(): void
    {
        $tags = [
            'tags:database:db:table_specific:cars',
            'tags:database:db:table_specific:cars:row:5',
            'tags:database:db:table_unspecific:drivers',
        ];

        $this->assertSame([
            'tags:database:db:table_specific:cars',
            'tags:database:db:table_unspecific:cars',
            'tags:database:db:table_specific:cars:row:5',
            'tags:database:db:table_unspecific:drivers',
        ], Tagger::legacyDependencies($tags));
    }

    public function testLegacyDependenciesKeepTagsWithoutATableAsTheyAre(): void
    {
        $this->assertSame(['tags:database:db.cars'], Tagger::legacyDependencies(['tags:database:db.cars']));
    }

    public function testSelectWithoutRowsWhenConsiderRowsFalseProducesDatabaseTableTags(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $b = DB::table('cars');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertSame([$this->dbTagPrefix().':table_unspecific:cars'], $tags);
    }

    public function testWithoutRowsTagsSeparateDatabaseAndTableNames(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $tags = (new Tagger(new Reflector(DB::table('ab'))))->getTags();

        $this->assertNotContains('tags:database:'.DB::connection()->getDatabaseName().'ab', $tags);
        $this->assertSame([$this->dbTagPrefix().':table_unspecific:ab'], $tags);
    }

    public function testSelectWithoutRowsWhenConsiderRowsTrueProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
        $this->assertFalse($this->arrayHasStringContaining($tags, ':row:'));
    }

    public function testSelectWithRowIdsProducesSpecificTableAndRowTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars')->whereIn('id', [1, 2]);
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_specific:cars', $tags);
        $this->assertContains($this->dbTagPrefix().':table_specific:cars:row:1', $tags);
        $this->assertContains($this->dbTagPrefix().':table_specific:cars:row:2', $tags);
    }

    public function testUpdateWithoutRowsProducesBothTableTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_UPDATE, ['name' => 'x']);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_specific:cars', $tags);
        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
    }

    public function testUpdateWithSpecificRowsProducesUnspecificAndRowTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars')->whereIn('id', [5, 10]);
        $r = new Reflector($b, Reflector::QUERY_TYPE_UPDATE, ['name' => 'updated']);
        $tags = (new Tagger($r))->getTags();

        // Invalidates aggregates but preserves row isolation
        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
        $this->assertContains($this->dbTagPrefix().':table_specific:cars:row:5', $tags);
        $this->assertContains($this->dbTagPrefix().':table_specific:cars:row:10', $tags);
        // Should NOT have table_specific without row suffix (preserves isolation)
        $this->assertNotContains($this->dbTagPrefix().':table_specific:cars', $tags);
    }

    public function testInsertProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_INSERT, ['name' => 'x']);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
    }

    public function testDeleteWithoutRowsProducesBothTableTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_DELETE);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_specific:cars', $tags);
        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
    }

    public function testTruncateProducesSpecificAndUnspecificTableTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_TRUNCATE);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_specific:cars', $tags);
        $this->assertContains($this->dbTagPrefix().':table_unspecific:cars', $tags);
    }

    public function testAliasNormalizationStripsAsAliasInTableNames(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $b = DB::table('engines as e');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains($this->dbTagPrefix().':table_unspecific:engines', $tags);
        $this->assertFalse($this->arrayHasStringContaining($tags, ' as '));
    }

    public function testTagsWithExpressionFromAliasAndWhereRows(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $sub = DB::table('materials')->select('id');
        $b = DB::query()
            ->fromSub($sub, 'materials')
            ->join('cars', 'cars.id', '=', 'materials.id')
            ->whereIn('cars.id', [10, 20]);

        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertTrue($this->arrayHasStringContaining($tags, ':table_unspecific:materials'));
        $this->assertFalse($this->arrayHasStringContaining($tags, ':table_unspecific:cars'));
        $this->assertTrue($this->arrayHasStringContaining($tags, ':table_specific:cars'));
        $this->assertTrue($this->arrayHasStringContaining($tags, ':table_specific:cars:row:10'));
        $this->assertTrue($this->arrayHasStringContaining($tags, ':table_specific:cars:row:20'));
    }

    private function arrayHasStringContaining(array $array, string $needle): bool
    {
        foreach ($array as $v) {
            if (is_string($v) && str_contains($v, $needle)) {
                return true;
            }
        }

        return false;
    }
}
