<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Engine;

class EngineFactory extends Factory
{
    /** @var class-string<\Spiritix\LadaCache\Tests\Database\Models\Engine> */
    protected $model = Engine::class;

    /**
     * @inheritDoc
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->word(),
            'car_id' => $this->faker->numberBetween(1, 1000),
        ];
    }
}
