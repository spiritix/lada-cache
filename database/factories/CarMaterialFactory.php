<?php

namespace Spiritix\LadaCache\Tests\Database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\CarMaterial;

class CarMaterialFactory extends Factory
{
    /**
     * @var string
     */
    protected $model = CarMaterial::class;

    /**
     * @return array
     */
    public function definition()
    {
        return [
            'car_id' => $this->faker->randomNumber(8),
            'material_id' => $this->faker->randomNumber(8),
        ];
    }
}
