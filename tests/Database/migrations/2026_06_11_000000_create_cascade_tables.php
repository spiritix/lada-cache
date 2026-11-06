<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('pets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->unsignedInteger('age')->default(0);
        });

        Schema::create('pet_toys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pet_id')->constrained('pets')->cascadeOnDelete();
            $table->string('name');
        });

        Schema::create('owner_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->nullable()->constrained('owners')->nullOnDelete();
            $table->string('body');
        });

        Schema::create('owner_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('owners')->restrictOnDelete();
            $table->string('event');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_audits');
        Schema::dropIfExists('owner_notes');
        Schema::dropIfExists('pet_toys');
        Schema::dropIfExists('pets');
        Schema::dropIfExists('owners');
    }
};
