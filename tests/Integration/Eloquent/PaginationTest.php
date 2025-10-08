<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\TestCase;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class PaginationTest extends TestCase
{
    use MakesTestModels;

    public function test_paginate(): void
    {
        foreach (range(1, 7) as $i) {
            $this->makeCar(['name' => 'c'.$i]);
        }

        $page1 = Car::orderBy('id')->paginate(perPage: 3, page: 1);
        $page2 = Car::orderBy('id')->paginate(perPage: 3, page: 2);
        $page3 = Car::orderBy('id')->paginate(perPage: 3, page: 3);

        Assert::assertSame(7, $page1->total());
        Assert::assertSame(3, $page1->count());
        Assert::assertSame(3, $page2->count());
        Assert::assertSame(1, $page3->count());
        Assert::assertSame(3, $page1->perPage());
        Assert::assertSame(3, $page1->lastPage());
    }

    public function test_simple_paginate(): void
    {
        foreach (range(1, 5) as $i) {
            $this->makeCar(['name' => 's'.$i]);
        }

        $page1 = Car::orderBy('id')->simplePaginate(perPage: 2, page: 1);
        $page2 = Car::orderBy('id')->simplePaginate(perPage: 2, page: 2);
        $page3 = Car::orderBy('id')->simplePaginate(perPage: 2, page: 3);

        Assert::assertSame(2, $page1->count());
        Assert::assertSame(2, $page2->count());
        Assert::assertSame(1, $page3->count());
        Assert::assertTrue($page1->hasMorePages());
        Assert::assertTrue($page2->hasMorePages());
        Assert::assertFalse($page3->hasMorePages());
    }
}

