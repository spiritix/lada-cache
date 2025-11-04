<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tagger;
use Spiritix\LadaCache\Tests\TestCase;

final class TaggerMatrixTest extends TestCase
{
    public function testSelectWhereInIdsIsSpecific(): void
    {
        config(['lada-cache.consider_rows' => true]);
        $b = DB::table('cars')->whereIn('id', [1, 2, 3]);
        $tags = (new Tagger(new Reflector($b)))->getTags();
        Assert::assertTrue($this->has($tags, ':table_specific:cars'));
        Assert::assertTrue($this->has($tags, ':table_specific:cars:row:1'));
        Assert::assertTrue($this->has($tags, ':table_specific:cars:row:2'));
        Assert::assertTrue($this->has($tags, ':table_specific:cars:row:3'));
        Assert::assertFalse($this->has($tags, ':table_unspecific:cars'));
    }

    public function testSelectWhereGreaterThanIdIsUnspecific(): void
    {
        config(['lada-cache.consider_rows' => true]);
        $b = DB::table('cars')->where('id', '>', 5);
        $tags = (new Tagger(new Reflector($b)))->getTags();
        Assert::assertTrue($this->has($tags, ':table_unspecific:cars'));
        Assert::assertFalse($this->has($tags, ':table_specific:cars:row:'));
    }

    public function testSelectWithNonPkConditionsIsUnspecific(): void
    {
        config(['lada-cache.consider_rows' => true]);
        $b = DB::table('cars')->where('name', 'like', 'A%');
        $tags = (new Tagger(new Reflector($b)))->getTags();
        Assert::assertTrue($this->has($tags, ':table_unspecific:cars'));
        Assert::assertFalse($this->has($tags, ':table_specific:cars:row:'));
    }

    private function has(array $tags, string $needle): bool
    {
        foreach ($tags as $v) {
            if (is_string($v) && str_contains($v, $needle)) {
                return true;
            }
        }
        return false;
    }
}
