<?php

declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Spiritix\LadaCache\Tests\Database\Models\Material;

class MaterialFactory extends Factory
{
    /** @var class-string<\Spiritix\LadaCache\Tests\Database\Models\Material> */
    protected $model = Material::class;

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
