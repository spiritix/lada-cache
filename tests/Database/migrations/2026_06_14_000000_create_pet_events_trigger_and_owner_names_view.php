<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pet_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pet_id');
        });

        $prefix = DB::connection()->getTablePrefix();
        $pets = $prefix.'pets';
        $events = $prefix.'pet_events';

        match (DB::connection()->getDriverName()) {
            'sqlite' => DB::unprepared("create trigger {$prefix}pets_record_event after insert on {$pets} begin insert into {$events} (pet_id) values (new.id); end"),
            'mysql', 'mariadb' => DB::unprepared("create trigger {$prefix}pets_record_event after insert on {$pets} for each row insert into {$events} (pet_id) values (new.id)"),
            'pgsql' => DB::unprepared(
                "create or replace function {$prefix}record_pet_event() returns trigger as \$\$ begin insert into {$events} (pet_id) values (new.id); return new; end; \$\$ language plpgsql;"
                ." create trigger {$prefix}pets_record_event after insert on {$pets} for each row execute function {$prefix}record_pet_event();"
            ),
        };

        DB::statement("create view {$prefix}owner_names as select id, name from {$prefix}owners");
    }

    public function down(): void
    {
        $prefix = DB::connection()->getTablePrefix();

        DB::statement("drop view if exists {$prefix}owner_names");
        Schema::dropIfExists('pet_events');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared("drop function if exists {$prefix}record_pet_event() cascade");
        }
    }
};
