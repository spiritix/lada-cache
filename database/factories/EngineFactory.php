<?php

namespace Spiritix\LadaCache\Tests\Database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Engine;

class EngineFactory extends Factory
{
    /**
     * @var string
     */
    protected $model = Engine::class;

    /**
     * @return array
     */
    public function definition()
    {
        return [
            'name' => $this->faker->word,
            'car_id' => $this->faker->randomNumber(8),
        ];
    }
}
