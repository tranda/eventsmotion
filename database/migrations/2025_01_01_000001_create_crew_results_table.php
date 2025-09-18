<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('crew_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crew_id')->constrained('crews')->onDelete('cascade');
            $table->foreignId('race_result_id')->constrained('race_results')->onDelete('cascade');
            $table->integer('position')->nullable();
            $table->string('time')->nullable(); // Format: "1:23.45"
            $table->string('delay_after_first')->nullable(); // Format: "+2.44"
            $table->enum('status', ['FINISHED', 'DNS', 'DNF', 'DSQ'])->default('FINISHED');
            $table->timestamps();
            
            $table->index(['race_result_id', 'position']);
            $table->index('crew_id');
            $table->unique(['crew_id', 'race_result_id']); // A crew can only have one result per race
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('crew_results');
    }
};