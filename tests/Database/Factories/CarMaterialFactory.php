<?php

namespace Spiritix\LadaCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\CarMaterial;

class CarMaterialFactory extends Factory
{
    /** @var class-string<\Spiritix\LadaCache\Tests\Database\Models\CarMaterial> */
    protected $model = CarMaterial::class;

    public function definition(): array
    {
        return [
            'car_id' => $this->faker->numberBetween(1, 1000),
            'material_id' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
