<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

class TaggerTest extends TestCase
{
    private const DB_TAG_PREFIX = 'tags:database::memory:';

    public function testSelectWithoutRowsWhenConsiderRowsFalseProducesDatabaseTableTags(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $b = DB::table('cars');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . 'cars', $tags);
        // No table_specific / table_unspecific when consider_rows is false
        $this->assertFalse($this->arrayHasStringContaining($tags, ':table_specific:'));
        $this->assertFalse($this->arrayHasStringContaining($tags, ':table_unspecific:'));
    }

    public function testSelectWithoutRowsWhenConsiderRowsTrueProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_unspecific:cars', $tags);
        $this->assertFalse($this->arrayHasStringContaining($tags, ':row:'));
    }

    public function testSelectWithRowIdsProducesSpecificTableAndRowTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars')->whereIn('id', [1, 2]);
        $tags = (new Tagger(new Reflector($b)))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_specific:cars', $tags);
        $this->assertContains(self::DB_TAG_PREFIX . ':table_specific:cars:row:1', $tags);
        $this->assertContains(self::DB_TAG_PREFIX . ':table_specific:cars:row:2', $tags);
    }

    public function testUpdateProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_UPDATE, ['name' => 'x']);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_unspecific:cars', $tags);
    }

    public function testInsertProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_INSERT, ['name' => 'x']);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_unspecific:cars', $tags);
    }

    public function testDeleteProducesUnspecificTableTag(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_DELETE);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_unspecific:cars', $tags);
    }

    public function testTruncateProducesSpecificAndUnspecificTableTags(): void
    {
        config(['lada-cache.consider_rows' => true]);

        $b = DB::table('cars');
        $r = new Reflector($b, Reflector::QUERY_TYPE_TRUNCATE);
        $tags = (new Tagger($r))->getTags();

        $this->assertContains(self::DB_TAG_PREFIX . ':table_specific:cars', $tags);
        $this->assertContains(self::DB_TAG_PREFIX . ':table_unspecific:cars', $tags);
    }

    public function testAliasNormalizationStripsAsAliasInTableNames(): void
    {
        config(['lada-cache.consider_rows' => false]);

        $b = DB::table('engines as e');
        $tags = (new Tagger(new Reflector($b)))->getTags();

        // When consider_rows=false, Tagger prefixes database + raw table name (alias stripped)
        $this->assertContains(self::DB_TAG_PREFIX . 'engines', $tags);
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
        $this->assertTrue($this->arrayHasStringContaining($tags, ':table_unspecific:cars'));
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