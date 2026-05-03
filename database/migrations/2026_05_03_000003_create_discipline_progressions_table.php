<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('discipline_progressions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('discipline_id')->unique()->constrained('disciplines')->onDelete('cascade');
            $table->string('race_plan_code')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('discipline_progressions');
    }
};
