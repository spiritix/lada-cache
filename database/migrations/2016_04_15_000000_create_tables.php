<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTables extends Migration
{
	public function up()
	{
		Schema::create('cars', function(Blueprint $table) {
			$table->increments('id');
            $table->integer('engine_id');
            $table->integer('driver_id');
			$table->string('name');
            $table->timestamps();
		});

        Schema::create('engines', function(Blueprint $table) {
            $table->increments('id');
            $table->integer('car_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('drivers', function(Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('materials', function(Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('car_material', function(Blueprint $table) {
            $table->integer('car_id');
            $table->integer('material_id');
            $table->timestamps();
        });
	}
}
