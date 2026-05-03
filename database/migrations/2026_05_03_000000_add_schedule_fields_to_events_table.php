<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->unsignedTinyInteger('lane_count')->default(6)->after('available');
            $table->enum('schedule_status', ['draft', 'published'])->default('published')->after('lane_count');
            $table->dateTime('schedule_published_at')->nullable()->after('schedule_status');
        });
    }

    public function down()
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['lane_count', 'schedule_status', 'schedule_published_at']);
        });
    }
};
