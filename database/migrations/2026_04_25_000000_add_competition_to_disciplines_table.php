<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('disciplines', function (Blueprint $table) {
            $table->string('competition')->nullable()->after('boat_group');
        });
    }

    public function down()
    {
        Schema::table('disciplines', function (Blueprint $table) {
            $table->dropColumn('competition');
        });
    }
};
