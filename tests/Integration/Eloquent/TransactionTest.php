<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\Eloquent;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Spiritix\LadaCache\Tests\Concerns\MakesTestModels;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class TransactionTest extends TestCase
{
    use MakesTestModels;

    public function test_transaction_commit(): void
    {
        DB::transaction(function (): void {
            Car::create(['name' => 'tx-commit']);
        });

        Assert::assertSame(1, Car::where('name', 'tx-commit')->count());
    }

    public function test_transaction_rollback_on_exception(): void
    {
        try {
            DB::transaction(function (): void {
                Car::create(['name' => 'tx-rollback']);
                throw new \RuntimeException('fail');
            });
            Assert::fail('Expected exception was not thrown');
        } catch (\RuntimeException $e) {
            Assert::assertSame('fail', $e->getMessage());
        }

        Assert::assertSame(0, Car::where('name', 'tx-rollback')->count());
    }
}

