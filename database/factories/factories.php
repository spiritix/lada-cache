<?php

use Faker\Generator as Faker;
use Spiritix\LadaCache\Tests\Database\Models\Car;
use Spiritix\LadaCache\Tests\Database\Models\Driver;
use Spiritix\LadaCache\Tests\Database\Models\Engine;
use Spiritix\LadaCache\Tests\Database\Models\Material;
use Spiritix\LadaCache\Tests\Database\Models\CarMaterial;

$factory->define(Car::class, function (Faker $faker) {
    return [
    'name' => $faker->word,
    'engine_id' => $faker->randomNumber(8),
    'driver_id' => $faker->randomNumber(8),
    ];
});

$factory->define(Engine::class, function (Faker $faker) {
    return [
        'name' => $faker->word,
        'car_id' => $faker->randomNumber(8),
    ];
});

$factory->define(Driver::class, function (Faker $faker) {
    return [
        'name' => $faker->word,
    ];
});

$factory->define(Material::class, function (Faker $faker) {
    return [
        'name' => $faker->word,
    ];
});

$factory->define(CarMaterial::class, function (Faker $faker) {
    return [
        'car_id' => $faker->randomNumber(8),
       'material_id' => $faker->randomNumber(8),
    ];
});
