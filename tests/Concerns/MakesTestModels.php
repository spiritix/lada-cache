<?php
declare(strict_types=1);

namespace Spiritix\LadaCache\Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\CarMaterial;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\Database\Models\Material;

trait MakesTestModels
{
    /** @return Car */
    protected function makeCar(array $attributes = [], ?int $count = null): Model|Car
    {
        $factory = Car::factory()->state($attributes);
        return $count ? $factory->count($count)->create() : $factory->create();
    }

    /** @return Driver */
    protected function makeDriver(array $attributes = []): Driver
    {
        return Driver::factory()->state($attributes)->create();
    }

    /** @return Engine */
    protected function makeEngine(array $attributes = []): Engine
    {
        return Engine::factory()->state($attributes)->create();
    }

    /** @return Material */
    protected function makeMaterial(array $attributes = []): Material
    {
        return Material::factory()->state($attributes)->create();
    }

    /** @return CarMaterial */
    protected function makeCarMaterial(array $attributes = []): CarMaterial
    {
        return CarMaterial::factory()->state($attributes)->create();
    }
}

