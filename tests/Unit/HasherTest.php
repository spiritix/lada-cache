<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Spiritix\LadaCache\Hasher;
use Spiritix\LadaCache\Reflector;
use Spiritix\LadaCache\Tests\TestCase;

class HasherTest extends TestCase
{
    public function testHashIsDeterministicForSameQuery(): void
    {
        $builder = DB::table('cars')->where('id', 1);
        $reflector = new Reflector($builder);
        $hasher = new Hasher($reflector);

        $h1 = $hasher->getHash();
        $h2 = $hasher->getHash();

        $this->assertSame($h1, $h2);
    }

    public function testHashDiffersWhenParametersChange(): void
    {
        $r1 = new Reflector(DB::table('cars')->where('id', 1));
        $r2 = new Reflector(DB::table('cars')->where('id', 2));

        $h1 = (new Hasher($r1))->getHash();
        $h2 = (new Hasher($r2))->getHash();

        $this->assertNotSame($h1, $h2);
    }

    public function testHashDiffersWhenSqlChanges(): void
    {
        $r1 = new Reflector(DB::table('cars')->where('id', 1));
        $r2 = new Reflector(DB::table('drivers')->where('id', 1));

        $h1 = (new Hasher($r1))->getHash();
        $h2 = (new Hasher($r2))->getHash();

        $this->assertNotSame($h1, $h2);
    }
}

