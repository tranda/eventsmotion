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
        Schema::create('disciplines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->string('distance')->nullable(); // e.g., "500m", "1000m", "5000m"
            $table->string('age_group')->nullable(); // e.g., "U18", "U23", "Senior", "Masters"
            $table->string('gender_group')->nullable(); // e.g., "M", "W", "X" (Mixed)
            $table->string('boat_group')->nullable(); // e.g., "K1", "K2", "K4", "C1", "C2"
            $table->string('status')->default('active'); // e.g., "active", "inactive", "completed"
            $table->timestamps();

            // Add indexes for better query performance
            $table->index(['event_id', 'status']);
            $table->index(['distance', 'age_group', 'gender_group', 'boat_group']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('disciplines');
    }
};