<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('schedule_blocks', function (Blueprint $table) {
            // text (not json) — older MariaDB doesn't accept the JSON column
            // type. Model casts ('array') handle JSON encode/decode at runtime.
            $table->text('competition_filter')->nullable()->after('stage_filter');
        });
    }

    public function down()
    {
        Schema::table('schedule_blocks', function (Blueprint $table) {
            $table->dropColumn('competition_filter');
        });
    }
};
