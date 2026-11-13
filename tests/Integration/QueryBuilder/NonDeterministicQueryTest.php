<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\Concerns\CacheAssertions;
use Spiritix\LadaCache\Tests\TestCase;

class NonDeterministicQueryTest extends TestCase
{
    use CacheAssertions;

    public function testQueriesComparingAgainstTheCurrentTimeAreNotCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'C', 'deleted_at' => '2000-01-01 00:00:00']);

        $builder = DB::table('cars')->whereRaw('deleted_at <= CURRENT_TIMESTAMP');
        $key = (new Hasher(new Reflector($builder)))->getHash();

        $this->assertCount(1, $builder->get());
        $this->assertCacheMissing($key);
    }

    public function testQueriesOrderedRandomlyAreNotCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'C']);

        $builder = DB::table('cars')->inRandomOrder();
        $key = (new Hasher(new Reflector($builder)))->getHash();

        $builder->get();
        $this->assertCacheMissing($key);
    }

    public function testQueriesWithoutNonDeterministicFunctionsAreStillCached(): void
    {
        DB::table('cars')->insert(['id' => 1, 'name' => 'now()']);

        $builder = DB::table('cars')->whereRaw("name = 'now()'")->orderBy('created_at');
        $key = (new Hasher(new Reflector($builder)))->getHash();

        $builder->get();
        $this->assertCacheHas($key);
    }
}
