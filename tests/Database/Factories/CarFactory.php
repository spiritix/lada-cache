<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class CarFactory extends Factory
{
    /** @var class-string<\Spiritix\LadaCache\Tests\Database\Models\Car> */
    protected $model = Car::class;

    /**
     * @inheritDoc
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'engine_id' => $this->faker->numberBetween(1, 1000),
            'driver_id' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
