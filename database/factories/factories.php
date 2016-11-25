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
    'engine_id' => $faker->randomNumber(8),
    'driver_id' => $faker->randomNumber(8),
]);

$factory(Engine::class, [
    'name' => $faker->word,
    'car_id' => $faker->randomNumber(8),
]);

$factory(Driver::class, [
    'name' => $faker->word,
]);

$factory(Material::class, [
    'name' => $faker->word,
]);

$factory(CarMaterial::class, [
    'car_id' => $faker->randomNumber(8),
    'material_id' => $faker->randomNumber(8),
]);