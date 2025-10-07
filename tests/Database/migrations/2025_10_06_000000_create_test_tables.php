<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('engine_id')->nullable();
            $table->foreignId('driver_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('engines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('car_id')->nullable();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('car_material', function (Blueprint $table) {
            $table->foreignId('car_id');
            $table->foreignId('material_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('car_material');
        Schema::dropIfExists('materials');
        Schema::dropIfExists('drivers');
        Schema::dropIfExists('engines');
        Schema::dropIfExists('cars');
    }
};
