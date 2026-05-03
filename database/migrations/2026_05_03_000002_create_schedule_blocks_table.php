<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('schedule_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_day_id')->constrained('event_days')->onDelete('cascade');
            $table->string('name');
            $table->time('start_time');
            $table->unsignedInteger('gap_seconds')->default(240);
            $table->json('gender_filter')->nullable();
            $table->json('distance_filter')->nullable();
            $table->json('stage_filter')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_day_id', 'sort_order']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('schedule_blocks');
    }
};
