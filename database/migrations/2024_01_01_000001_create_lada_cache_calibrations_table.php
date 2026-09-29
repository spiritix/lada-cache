<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lada_cache_calibrations', function (Blueprint $table): void {
            $table->id();
            $table->string('model_class')->unique();
            $table->string('table_name')->index();
            $table->unsignedInteger('calibrated_ttl');
            $table->json('metrics');
            $table->timestamp('calibrated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lada_cache_calibrations');
    }
};
