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
        Schema::create('race_results', function (Blueprint $table) {
            $table->id();
            $table->integer('race_number');
            $table->foreignId('discipline_id')->constrained('disciplines')->onDelete('cascade');
            $table->dateTime('race_time')->nullable();
            $table->string('title')->nullable();
            $table->string('stage'); // "Round 1", "Heat 1", "Semifinal A", "Final", etc.
            $table->enum('status', ['SCHEDULED', 'IN_PROGRESS', 'FINISHED', 'CANCELLED'])->default('SCHEDULED');
            $table->timestamps();
            
            $table->index(['discipline_id', 'status']);
            $table->index('race_number');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('race_results');
    }
};