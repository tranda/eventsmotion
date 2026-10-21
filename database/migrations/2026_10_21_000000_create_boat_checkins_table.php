<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per athlete the boat marshal let on board for a specific race.
 * stage / race_number are copied from the race at scan time so the record
 * still says "Heat 2" even if the schedule is regenerated later.
 */
return new class extends Migration
{
    public function up()
    {
        Schema::create('boat_checkins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('race_result_id')->constrained('race_results')->onDelete('cascade');
            $table->unsignedBigInteger('crew_id');
            $table->unsignedBigInteger('athlete_id');
            $table->string('stage')->nullable();
            $table->unsignedInteger('race_number')->nullable();
            $table->unsignedInteger('seat_no')->nullable(); // crew_athletes.no at scan time
            $table->string('role', 20)->nullable();          // drummer | paddler | helm | reserve
            $table->unsignedBigInteger('checked_by')->nullable();
            $table->timestamp('checked_at');
            $table->timestamps();

            // An athlete boards at most one boat per race.
            $table->unique(['race_result_id', 'athlete_id']);
            $table->index(['race_result_id', 'crew_id']);
            $table->index('athlete_id');
        });
    }

    public function down()
    {
        Schema::dropIfExists('boat_checkins');
    }
};
