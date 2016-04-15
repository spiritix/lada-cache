<?php

use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\CarMaterial;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\Database\Models\Material;

/* @var $factory callable */
/* @var $faker \Laracasts\TestDummy\FakerAdapter */

$factory(Car::class, [
    'name' => $faker->word,
    'engine_id' => '1',
    'driver_id' => '1',
]);

$factory(Engine::class, [
    'name' => $faker->word,
    'car_id' => '1',
]);

$factory(Driver::class, [
    'name' => $faker->word,
]);

$factory(Material::class, [
    'name' => $faker->word,
]);

$factory(CarMaterial::class, [
    'car_id' => '1',
    'material_id' => '1',
]);