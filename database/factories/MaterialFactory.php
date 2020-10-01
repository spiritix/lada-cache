<?php

namespace Spiritix\LadaCache\Tests\Database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Material;

class MaterialFactory extends Factory
{
    /**
     * @var string
     */
    protected $model = Material::class;

    /**
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->word,
        ];
    }
}
