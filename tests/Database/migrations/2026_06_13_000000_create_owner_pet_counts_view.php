<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = DB::connection()->getTablePrefix();

        DB::statement(
            "create view {$prefix}owner_pet_counts as select {$prefix}owners.id as owner_id, count({$prefix}pets.id) as pet_count"
            ." from {$prefix}owners left join {$prefix}pets on {$prefix}pets.owner_id = {$prefix}owners.id group by {$prefix}owners.id"
        );
    }

    public function down(): void
    {
        DB::statement('drop view if exists '.DB::connection()->getTablePrefix().'owner_pet_counts');
    }
};
