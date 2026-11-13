<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Integration\QueryBuilder;

use Spiritix\LadaCache\Database\QueryBuilder;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\TestCase;

class WithoutCacheDisabledTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Boot Lada Cache in the disabled state so DB::extend and singletons stay unregistered.
        $app['config']->set('lada-cache.active', false);
    }

    public function testEloquentMacroIsGracefulNoopWhenDisabled(): void
    {
        $builder = Car::query()->where('id', 1);

        // Macro must not throw when Lada is disabled; the underlying query is a vanilla Laravel Builder.
        $returned = $builder->withoutCache();

        $this->assertSame($builder, $returned);
        $this->assertNotInstanceOf(QueryBuilder::class, $builder->getQuery());
    }
}
