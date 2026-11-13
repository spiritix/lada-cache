<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_renewals', function (Blueprint $table) {
            $table->id();
            $table->string('code');
            $table->string('note');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_renewals');
    }
};
