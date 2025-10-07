<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Driver;

class DriverFactory extends Factory
{
    /** @var class-string<\Spiritix\LadaCache\Tests\Database\Models\Driver> */
    protected $model = Driver::class;

    /**
     * @inheritDoc
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
        ];
    }
}
