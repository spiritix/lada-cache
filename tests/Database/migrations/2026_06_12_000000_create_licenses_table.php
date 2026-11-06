<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('licenses', function (Blueprint $table) {
            $table->string('code')->primary();
            $table->string('holder');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('licenses');
    }
};
