<?php

namespace Spiritix\LadaCache\Tests\Database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Car;

class CarFactory extends Factory
{
    /**
     * @var string
     */
    protected $model = Car::class;

    /**
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'engine_id' => $this->faker->randomNumber(8),
            'driver_id' => $this->faker->randomNumber(8),
        ];
    }
}
