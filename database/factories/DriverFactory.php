<?php

namespace Spiritix\LadaCache\Tests\Database\factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Driver;

class DriverFactory extends Factory
{
    /**
     * @var string
     */
    protected $model = Driver::class;

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
